<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MyResultsController extends AbstractController
{
    public function __construct(
        private readonly Connection $conn
    ) {
    }

    #[Route('/sportabzeichen/my_results', name: 'my_results')]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $currentYear = (int)date('Y');
        
        // --- 1. ID ERMITTELN ---
        // Sicherstellen, dass wir die interne DB-ID des Users haben
        $username = method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : $user->getUsername();
        $userId = $this->conn->fetchOne("SELECT id FROM users WHERE act = ?", [$username]);

        if (!$userId) {
            throw $this->createNotFoundException('User-Daten konnten nicht geladen werden.');
        }

        // --- 2. TEILNEHMER-PROFIL ---
        $participant = $this->conn->fetchAssociative("
            SELECT id, geburtsdatum, geschlecht 
            FROM sportabzeichen_participants 
            WHERE user_id = :uid
        ", ['uid' => $userId]);

        // Auto-Create falls Profil fehlt (z.B. erster Login)
        if (!$participant) {
            $this->conn->executeStatement("
                INSERT INTO sportabzeichen_participants (user_id, username, geburtsdatum, geschlecht)
                VALUES (:uid, :act, :dob, :sex)
            ", [
                'uid' => $userId,
                'act' => $username,
                'dob' => '2000-01-01', // Standardwert, sollte User später ändern
                'sex' => 'MALE'
            ]);
            
            $participant = $this->conn->fetchAssociative("SELECT id, geburtsdatum, geschlecht FROM sportabzeichen_participants WHERE user_id = :uid", ['uid' => $userId]);
            $this->addFlash('info', 'Dein Sportprofil wurde erstellt.');
        }

        // --- 3. ALTER BERECHNEN (Sportabzeichen-Logik: Jahr - Geburtsjahr) ---
        $birthYear = (int)(new \DateTime($participant['geburtsdatum']))->format('Y');
        $age = $currentYear - $birthYear;

        // --- 4. OFFIZIELLE ERGEBNISSE LADEN ---
        $sqlResults = "
            SELECT r.discipline_id, r.leistung, r.points
            FROM sportabzeichen_exam_results r
            JOIN sportabzeichen_exam_participants ep ON r.ep_id = ep.id
            JOIN sportabzeichen_exams e ON ep.exam_id = e.id
            WHERE ep.participant_id = :pid AND e.exam_year = :year
        ";
        $rawResults = $this->conn->fetchAllAssociative($sqlResults, [
            'pid' => (int)$participant['id'],
            'year' => $currentYear
        ]);
        
        $officialResults = [];
        foreach ($rawResults as $r) {
            $officialResults[$r['discipline_id']] = $r;
        }

        // --- 5. TRAININGSDATEN LADEN (Neuester Wert pro Disziplin) ---
        // WICHTIG: Die Subquery braucht die User_ID um konsistent zu sein
        $trainingData = $this->conn->fetchAllAssociative("
            SELECT t.discipline_id, t.value
            FROM sportabzeichen_training t
            WHERE t.id IN (
                SELECT MAX(id) 
                FROM sportabzeichen_training 
                WHERE user_id = :uid AND year = :year
                GROUP BY discipline_id
            )
        ", [
            'uid'  => (int)$userId,
            'year' => $currentYear
        ]);
        
        $myTraining = [];
        foreach ($trainingData as $t) {
            $myTraining[$t['discipline_id']] = $t['value'];
        }

        // --- 6. ANFORDERUNGEN (Requirements) ---
        $sqlReq = "
            SELECT 
                d.id as discipline_id, d.name, d.kategorie, d.einheit,
                r.bronze, r.silber, r.gold
            FROM sportabzeichen_requirements r
            JOIN sportabzeichen_disciplines d ON r.discipline_id = d.id
            WHERE r.geschlecht = :sex 
              AND :age BETWEEN r.age_min AND r.age_max
              AND r.jahr = :year
            ORDER BY d.kategorie ASC, d.name ASC
        ";
        
        $rows = $this->conn->fetchAllAssociative($sqlReq, [
            'sex'  => $participant['geschlecht'],
            'age'  => $age,
            'year' => $currentYear
        ]);

        // --- 7. STRUKTURIEREN FÜR DAS TEMPLATE ---
        $categories = [
            'Ausdauer' => [], 
            'Kraft' => [], 
            'Schnelligkeit' => [], 
            'Koordination' => []
        ];

        foreach ($rows as $row) {
            $dId = $row['discipline_id'];
            
            // Verknüpfe offizielle Daten und Training
            $row['official_result'] = $officialResults[$dId] ?? null;
            $row['training_value'] = $myTraining[$dId] ?? null;

            $cat = $row['kategorie'];
            if (isset($categories[$cat])) {
                $categories[$cat][] = $row;
            }
        }

        return $this->render('my_results/index.html.twig', [
            'year' => $currentYear,
            'age' => $age,
            'categories' => $categories,
        ]);
    }
}