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

        // --- FIX 1: ID SICHER ERMITTELN ---
        // Wenn du das User-Objekt hast, nutze direkt getId(), statt erneut die DB zu fragen
        // Falls dein User-Entity getId() hat:
        $userId = $user->getId(); 
        
        $currentYear = (int)date('Y');

        // --- FIX 2: PARTICIPANT CHECK ---
        $participant = $this->conn->fetchAssociative("
            SELECT geburtsdatum, geschlecht 
            FROM sportabzeichen_participants 
            WHERE user_id = ?
        ", [$userId]);

        // Wenn kein Profil existiert, können wir keine Details anzeigen
        if (!$participant) {
            $this->addFlash('warning', 'Bitte erstelle zuerst dein Profil unter "Meine Ergebnisse".');
            return $this->redirectToRoute('my_results');
        }

        // --- FIX 3: DATUM CHECK ---
        $birthDate = new \DateTime($participant['geburtsdatum'] ?? '2000-01-01');
        $age = $currentYear - (int)$birthDate->format('Y');

        $req = $this->conn->fetchAssociative("
            SELECT d.name, d.einheit, r.bronze, r.silber, r.gold
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

        // Hier wird die Exception geworfen, falls keine Anforderungen gefunden werden
        if (!$req) {
            throw $this->createNotFoundException('Disziplin für dein Alter/Geschlecht nicht verfügbar.');
        }

        // --- SPEICHERN (unverändert, aber mit sicherer userId) ---
        if ($request->isMethod('POST')) {
            $newValue = trim((string)$request->request->get('value'));
            if ($newValue !== '') {
                // Tipp: Ersetze Komma durch Punkt für mathematische Korrektheit
                $newValue = str_replace(',', '.', $newValue); 

                $this->conn->executeStatement("
                    INSERT INTO sportabzeichen_training (user_id, discipline_id, year, value, created_at)
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

        // --- HISTORIE & ANALYSE ---
        $history = $this->conn->fetchAllAssociative("
            SELECT value, created_at 
            FROM sportabzeichen_training 
            WHERE user_id = :uid AND discipline_id = :did AND year = :yr
            ORDER BY created_at DESC
        ", [
            'uid' => $userId,
            'did' => $disciplineId,
            'yr'  => $currentYear
        ]);

        // Sicherstellen, dass history[0] existiert, bevor man analyzePerformance aufruft
        $latestValue = $history[0]['value'] ?? null;
        $analysis = $this->analyzePerformance($latestValue, $req);

        return $this->render('my_results/detail.html.twig', [
            'discipline' => $req,
            'history' => $history,
            'analysis' => $analysis,
            'year' => $currentYear
        ]);
    }
}