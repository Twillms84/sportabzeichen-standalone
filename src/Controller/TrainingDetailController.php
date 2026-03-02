<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sportabzeichen/training/{disciplineId}', name: 'training_detail')]
class TrainingDetailController extends AbstractController
{
    public function __construct(
        private readonly Connection $conn
    ) {
    }

    public function __invoke(int $disciplineId, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException('Nicht eingeloggt.');
        }

        // --- 1. ID ERMITTELN ---
        // Direkt vom User-Objekt, das ist am sichersten.
        $userId = $user->getId(); 
        $currentYear = (int)date('Y');

        // --- 2. PARTICIPANT CHECK ---
        $participant = $this->conn->fetchAssociative("
            SELECT geburtsdatum, geschlecht 
            FROM sportabzeichen_participants 
            WHERE user_id = ?
        ", [$userId]);

        if (!$participant) {
            $this->addFlash('warning', 'Bitte erstelle zuerst dein Profil unter "Meine Ergebnisse".');
            return $this->redirectToRoute('my_results');
        }

        // --- 3. ALTER & ANFORDERUNGEN ---
        $birthDate = new \DateTime($participant['geburtsdatum'] ?? '2000-01-01');
        $age = $currentYear - (int)$birthDate->format('Y');

        $req = $this->conn->fetchAssociative("
            SELECT d.id, d.name, d.einheit, r.bronze, r.silber, r.gold
            FROM sportabzeichen_requirements r
            JOIN sportabzeichen_disciplines d ON r.discipline_id = d.id
            WHERE d.id = :did 
              AND r.geschlecht = :sex
              AND :age BETWEEN r.age_min AND r.age_max
              AND r.jahr = :year
        ", [
            'did' => $disciplineId,
            'sex' => $participant['geschlecht'],
            'age' => $age,
            'year' => $currentYear
        ]);

        if (!$req) {
            throw $this->createNotFoundException('Disziplin für dein Alter/Geschlecht nicht verfügbar.');
        }

        // --- 4. SPEICHERN (PostgreSQL Fix: updated_at statt created_at) ---
        if ($request->isMethod('POST')) {
            $newValue = trim((string)$request->request->get('value'));
            if ($newValue !== '') {
                $newValue = str_replace(',', '.', $newValue); 

                $this->conn->executeStatement("
                    INSERT INTO sportabzeichen_training (user_id, discipline_id, year, value, updated_at)
                    VALUES (:uid, :did, :yr, :val, NOW())
                ", [
                    'uid' => $userId,
                    'did' => $disciplineId,
                    'yr'  => $currentYear,
                    'val' => $newValue
                ]);
                $this->addFlash('success', 'Trainingseinheit gespeichert!');
                return $this->redirectToRoute('training_detail', ['disciplineId' => $disciplineId]);
            }
        }

        // --- 5. HISTORIE & ANALYSE (PostgreSQL Fix: updated_at) ---
        // Wir nutzen "AS created_at", damit Twig im Template weiterhin entry.created_at nutzen kann
        $history = $this->conn->fetchAllAssociative("
            SELECT value, updated_at AS created_at 
            FROM sportabzeichen_training 
            WHERE user_id = :uid AND discipline_id = :did AND year = :yr
            ORDER BY updated_at DESC
        ", [
            'uid' => $userId,
            'did' => $disciplineId,
            'yr'  => $currentYear
        ]);

        $latestValue = $history[0]['value'] ?? null;
        $analysis = $this->analyzePerformance($latestValue, $req);

        return $this->render('my_results/detail.html.twig', [
            'discipline' => $req,
            'history' => $history,
            'analysis' => $analysis,
            'year' => $currentYear
        ]);
    }

    private function analyzePerformance(?string $currentVal, array $req): array
    {
        if ($currentVal === null) return [];

        $val = (float)str_replace(',', '.', $currentVal);
        $gold = (float)str_replace(',', '.', (string)$req['gold']);
        $bronze = (float)str_replace(',', '.', (string)$req['bronze']);

        // Logik: Ist weniger besser (Zeit) oder mehr besser (Weite/Anzahl)?
        $lessIsBetter = ($gold < $bronze);

        $targets = [
            'Bronze' => (float)str_replace(',', '.', (string)$req['bronze']),
            'Silber' => (float)str_replace(',', '.', (string)$req['silber']),
            'Gold'   => (float)str_replace(',', '.', (string)$req['gold']),
        ];

        $nextGoal = null;
        $gap = 0;

        foreach ($targets as $label => $targetVal) {
            $reached = $lessIsBetter ? ($val <= $targetVal) : ($val >= $targetVal);
            if (!$reached) {
                $nextGoal = $label;
                $gap = $lessIsBetter ? ($val - $targetVal) : ($targetVal - $val);
                break;
            }
        }

        return [
            'current_float' => $val,
            'next_goal_label' => $nextGoal,
            'gap' => round($gap, 2),
            'unit' => $req['einheit'],
            'less_is_better' => $lessIsBetter
        ];
    }
}