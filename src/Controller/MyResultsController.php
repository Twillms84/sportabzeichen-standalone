<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\User; // Wichtig für Autovervollständigung

class MyResultsController extends AbstractController
{
    public function __construct(
        private readonly Connection $conn
    ) {
    }

    #[Route('/sportabzeichen/my_results', name: 'my_results')]
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        if (!$user) {
            throw $this->createAccessDeniedException('Bitte loggen Sie sich ein.');
        }

        $currentYear = (int)date('Y');
        
        // --- 1. ID ERMITTELN (Jetzt 100% sicher über das Entity) ---
        $userId = $user->getId();
        // Wir nehmen getUsername als Fallback für das Participant-Profil, falls kein act gesetzt ist
        $username = $user->getAct() ?? $user->getUsername() ?? $user->getEmail() ?? 'user_' . $userId;

        // --- 2. TEILNEHMER-PROFIL PRÜFEN ---
        $participant = $this->conn->fetchAssociative("
            SELECT id, geburtsdatum, geschlecht 
            FROM sportabzeichen_participants 
            WHERE user_id = :uid
        ", ['uid' => $userId]);

        // Auto-Create falls Profil fehlt (z.B. beim allerersten Klick auf "Meine Ergebnisse")
        if (!$participant) {
            $this->conn->executeStatement("
                INSERT INTO sportabzeichen_participants (user_id, username, geburtsdatum, geschlecht)
                VALUES (:uid, :act, :dob, :sex)
            ", [
                'uid' => $userId,
                'act' => $username,
                'dob' => '2000-01-01', // Standardwert (sollte der User im Profil anpassen)
                'sex' => 'MALE'        // Standardwert
            ]);
            
            $participant = $this->conn->fetchAssociative("
                SELECT id, geburtsdatum, geschlecht 
                FROM sportabzeichen_participants 
                WHERE user_id = :uid
            ", ['uid' => $userId]);
            
            $this->addFlash('info', 'Dein Sportprofil wurde automatisch erstellt. Bitte überprüfe dein Geburtsdatum in den Einstellungen.');
        }

        // --- 3. ALTER BERECHNEN ---
        // Sportabzeichen-Logik: (Aktuelles Jahr) - (Geburtsjahr) = Alter in dem Jahr
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
            'pid'  => (int)$participant['id'],
            'year' => $currentYear
        ]);
        
        $officialResults = [];
        foreach ($rawResults as $r) {
            $officialResults[$r['discipline_id']] = $r;
        }

        // --- 5. TRAININGSDATEN LADEN ---
        // Holt nur den jeweils neusten Eintrag (MAX(id)) pro Disziplin
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
            'uid'  => $userId,
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

        // --- 7. ZUSAMMENBAU FÜRS TEMPLATE ---
        $categories = [
            'Ausdauer'      => [], 
            'Kraft'         => [], 
            'Schnelligkeit' => [], 
            'Koordination'  => []
        ];

        foreach ($rows as $row) {
            $dId = $row['discipline_id'];
            
            // Verknüpfe offizielle Daten und eigenes Training
            $row['official_result'] = $officialResults[$dId] ?? null;
            $row['training_value']  = $myTraining[$dId] ?? null;

            $cat = $row['kategorie'];
            if (isset($categories[$cat])) {
                $categories[$cat][] = $row;
            } else {
                 // Fallback, falls mal eine Kategorie in der DB anders heißt
                 $categories[$cat] = [$row];
            }
        }

        return $this->render('my_results/index.html.twig', [
            'year'       => $currentYear,
            'age'        => $age,
            'categories' => $categories,
        ]);
    }
}