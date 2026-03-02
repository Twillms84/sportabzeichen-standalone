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
        $userId = $user->getId();
        $username = $user->getAct() ?? $user->getUsername() ?? $user->getEmail() ?? 'user_' . $userId;

        // --- 1. TEILNEHMER-PROFIL ---
        $participant = $this->conn->fetchAssociative("
            SELECT id, geburtsdatum, geschlecht 
            FROM sportabzeichen_participants 
            WHERE user_id = :uid
        ", ['uid' => $userId]);

        if (!$participant) {
            $institutionId = $user->getInstitution()?->getId();
            if (!$institutionId) {
                throw $this->createAccessDeniedException('Keine Institution zugeordnet.');
            }

            $this->conn->executeStatement("
                INSERT INTO sportabzeichen_participants (user_id, username, geburtsdatum, geschlecht, institution_id)
                VALUES (:uid, :act, :dob, :sex, :inst_id)
            ", [
                'uid' => $userId, 'act' => $username, 'dob' => '2000-01-01', 'sex' => 'MALE', 'inst_id' => $institutionId 
            ]);
            
            $participant = $this->conn->fetchAssociative("SELECT id, geburtsdatum, geschlecht FROM sportabzeichen_participants WHERE user_id = :uid", ['uid' => $userId]);
        }

        $birthYear = (int)(new \DateTime($participant['geburtsdatum']))->format('Y');
        $age = $currentYear - $birthYear;

        // --- 2. OFFICIELLE ERGEBNISSE (JOIN optimiert) ---
        // WICHTIG: Wir joinen über sportabzeichen_exam_participants
        $sqlResults = "
            SELECT r.discipline_id, r.leistung, r.points
            FROM sportabzeichen_exam_results r
            INNER JOIN sportabzeichen_exam_participants ep ON r.ep_id = ep.id
            WHERE ep.participant_id = :pid 
            AND ep.exam_id IN (SELECT id FROM sportabzeichen_exams WHERE exam_year = :year)
        ";
        $rawResults = $this->conn->fetchAllAssociative($sqlResults, [
            'pid'  => (int)$participant['id'],
            'year' => $currentYear
        ]);
        
        $officialResults = [];
        foreach ($rawResults as $r) {
            $officialResults[$r['discipline_id']] = $r;
        }

        // --- 3. TRAININGSDATEN ---
        $trainingData = $this->conn->fetchAllAssociative("
            SELECT t.discipline_id, t.value
            FROM sportabzeichen_training t
            WHERE t.id IN (
                SELECT MAX(id) FROM sportabzeichen_training 
                WHERE user_id = :uid AND year = :year
                GROUP BY discipline_id
            )
        ", ['uid' => $userId, 'year' => $currentYear]);
        
        $myTraining = [];
        foreach ($trainingData as $t) {
            $myTraining[$t['discipline_id']] = $t['value'];
        }

        // --- 4. ANFORDERUNGEN (Mit Filtern gegen "Verband" und "Schwimmen") ---
        $sqlReq = "
            SELECT 
                d.id as discipline_id, d.name, d.kategorie, d.einheit,
                r.bronze, r.silber, r.gold
            FROM sportabzeichen_requirements r
            JOIN sportabzeichen_disciplines d ON r.discipline_id = d.id
            WHERE r.geschlecht = :sex 
              AND :age BETWEEN r.age_min AND r.age_max
              AND r.jahr = :year
              AND d.einheit NOT IN ('NONE', 'UNIT_NONE') -- Filtert 'Verband'
              AND d.kategorie != 'Schwimmen'             -- Filtert Kategorie Schwimmen
            ORDER BY d.kategorie ASC, d.name ASC
        ";
        
        $rows = $this->conn->fetchAllAssociative($sqlReq, [
            'sex'  => $participant['geschlecht'],
            'age'  => $age,
            'year' => $currentYear
        ]);

        $categories = ['Ausdauer' => [], 'Kraft' => [], 'Schnelligkeit' => [], 'Koordination' => []];

        foreach ($rows as $row) {
            $dId = $row['discipline_id'];
            $row['official_result'] = $officialResults[$dId] ?? null;
            $row['training_value']  = $myTraining[$dId] ?? null;

            $cat = $row['kategorie'];
            if (isset($categories[$cat])) {
                $categories[$cat][] = $row;
            }
        }

        return $this->render('my_results/index.html.twig', [
            'year' => $currentYear,
            'age' => $age,
            'categories' => array_filter($categories) // Entfernt leere Kategorien
        ]);
    }
}