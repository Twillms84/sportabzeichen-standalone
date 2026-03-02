<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\User;

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

        // Automatisches Profil-Fallback (falls noch keins existiert)
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

        // --- 2. OFFIZIELLE PRÜFER-ERGEBNISSE ---
        $sqlResults = "
            SELECT r.discipline_id, r.leistung, r.points
            FROM sportabzeichen_exam_results r
            JOIN sportabzeichen_exam_participants ep ON r.ep_id = ep.id
            JOIN sportabzeichen_exams e ON ep.exam_id = e.id
            WHERE ep.participant_id = :pid 
            AND e.exam_year = :year
        ";
        $rawResults = $this->conn->fetchAllAssociative($sqlResults, [
            'pid'  => (int)$participant['id'],
            'year' => $currentYear
        ]);

        $officialResults = [];
        foreach ($rawResults as $r) {
            $officialResults[(int)$r['discipline_id']] = [
                'leistung' => $r['leistung'],
                'points'   => (int)$r['points']
            ];
        }

        // --- 3. TRAININGSDATEN (Letzter Wert pro Disziplin) ---
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
            $myTraining[(int)$t['discipline_id']] = $t['value'];
        }

        // --- 4. ANFORDERUNGEN LADEN ---
        $sqlReq = "
            SELECT 
                d.id as discipline_id, d.name, d.kategorie, d.einheit,
                r.bronze, r.silber, r.gold
            FROM sportabzeichen_requirements r
            JOIN sportabzeichen_disciplines d ON r.discipline_id = d.id
            WHERE r.geschlecht = :sex 
              AND :age BETWEEN r.age_min AND r.age_max
              AND r.jahr = :year
              AND d.einheit NOT IN ('NONE', 'UNIT_NONE')
              AND d.kategorie != 'Schwimmen'
            ORDER BY d.kategorie ASC, d.name ASC
        ";
        
        $rows = $this->conn->fetchAllAssociative($sqlReq, [
            'sex'  => $participant['geschlecht'],
            'age'  => $age,
            'year' => $currentYear
        ]);

        // --- 5. DATEN VERHEIRATEN & HIGHLIGHT-LOGIK ---
        $categories = ['Ausdauer' => [], 'Kraft' => [], 'Schnelligkeit' => [], 'Koordination' => []];

        foreach ($rows as $row) {
            $dId = (int)$row['discipline_id'];
            
            // Ergebnisse zuordnen
            $row['official_result'] = $officialResults[$dId] ?? null;
            $row['training_value']  = $myTraining[$dId] ?? null;

            // Highlight-Logik für das Template
            $row['is_official'] = isset($row['official_result']);
            $row['best_value']  = $row['is_official'] ? $row['official_result']['leistung'] : ($row['training_value'] ?? null);
            $row['has_points']  = $row['is_official'] && ($row['official_result']['points'] ?? 0) > 0;

            $cat = $row['kategorie'];
            if (isset($categories[$cat])) {
                $categories[$cat][] = $row;
            }
        }

        // --- 6. SCHWIMMANACHWEIS ABFRAGEN ---
        $swimData = $this->conn->fetchAssociative("
            SELECT valid_until 
            FROM sportabzeichen_swimming_proofs 
            WHERE participant_id = :pid 
            ORDER BY valid_until DESC LIMIT 1
        ", ['pid' => (int)$participant['id']]);

        $isSwimValid = false;
        $swimValidUntil = null;

        if ($swimData) {
            // Falls DB-Datum ein String ist, in DateTime konvertieren
            $validUntilDate = new \DateTime($swimData['valid_until']);
            $isSwimValid = $validUntilDate >= new \DateTime('today');
            $swimValidUntil = $validUntilDate->format('d.m.Y');
        }

        return $this->render('my_results/index.html.twig', [
            'year' => $currentYear,
            'age' => $age,
            'categories' => array_filter($categories),
            'participant_sex' => $participant['geschlecht'],
            'swim_valid' => $isSwimValid,
            'swim_valid_until' => $swimValidUntil
        ]);
    }
}