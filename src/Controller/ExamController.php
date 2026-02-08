<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\Group;
use App\Entity\Exam;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/exams', name: 'app_exams_')]
final class ExamController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(Connection $conn): Response
    {
        //$this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // 1. Alle Prüfungen laden
        $exams = $conn->fetchAllAssociative("
            SELECT * FROM sportabzeichen_exams 
            ORDER BY exam_year DESC, exam_date DESC
        ");

        // 2. Performance-Query: Punkte aller Teilnehmer aller Prüfungen
        $sqlResults = "
            SELECT 
                e.exam_year,
                e.id as exam_id,
                p.user_id,
                SUM(COALESCE(r.points, 0)) as total_points
            FROM sportabzeichen_exams e
            JOIN sportabzeichen_exam_participants ep ON e.id = ep.exam_id
            JOIN sportabzeichen_participants p ON ep.participant_id = p.id
            LEFT JOIN sportabzeichen_exam_results r ON ep.id = r.ep_id
            GROUP BY e.id, ep.id, p.user_id
        ";
        
        $rawResults = $conn->fetchAllAssociative($sqlResults);

        // 3. Daten aggregieren
        $yearlyStats = [];

        foreach ($exams as $exam) {
            $year = $exam['exam_year'];
            if (!isset($yearlyStats[$year])) {
                $yearlyStats[$year] = [
                    'year' => $year,
                    'exams' => [],
                    'stats' => ['Gold' => 0, 'Silber' => 0, 'Bronze' => 0, 'Ohne' => 0, 'Total' => 0],
                    'unique_users' => [] 
                ];
            }
            $yearlyStats[$year]['exams'][] = $exam;
        }

        foreach ($rawResults as $row) {
            $year = $row['exam_year'];
            if (isset($yearlyStats[$year])) {
                $pts = (int)$row['total_points'];
                
                // Teilnehmer zählen (Unique Check via UserID)
                if (isset($yearlyStats[$year]['unique_users'][$row['user_id']])) {
                    // User schon gezählt -> Ergebnisse ignorieren oder addieren?
                    // In dieser Logik zählt das 'Beste' oder das 'Letzte', da wir einfach drüber loopen.
                    // Für eine saubere Statistik müsste man pro User summieren, das macht das SQL aber schon pro Exam.
                    // Da ein User mehrere Exams im Jahr haben kann, zählen wir ihn hier nur einmal für die 'Total'-Statistik.
                    continue; 
                }
                $yearlyStats[$year]['unique_users'][$row['user_id']] = true;

                if ($pts >= 11) {
                    $yearlyStats[$year]['stats']['Gold']++;
                } elseif ($pts >= 8) {
                    $yearlyStats[$year]['stats']['Silber']++;
                } elseif ($pts >= 4) {
                    $yearlyStats[$year]['stats']['Bronze']++;
                } else {
                    $yearlyStats[$year]['stats']['Ohne']++;
                }
                $yearlyStats[$year]['stats']['Total']++;
            }
        }

        return $this->render('exams/dashboard.html.twig', [
            'yearlyStats' => $yearlyStats,
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em, Connection $conn): Response
    {
        //$this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        // Gruppen laden
        $groupRepo = $em->getRepository(Group::class);
        $allGroups = $groupRepo->findBy([], ['name' => 'ASC']);

      $groupsForDropdown = [];
        foreach ($allGroups as $g) {
            // Der Name (z.B. "8b") ist das Label für den User, 
            // die ID (z.B. 42) ist der Wert, der an den Server geht.
            $groupsForDropdown[$g->getName()] = $g->getId();
        }

        if ($request->isMethod('POST')) {
            try {
                $name = trim($request->request->get('exam_name', ''));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;
                
                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;
                
                $postData = $request->request->all();
                $selectedGroups  = $postData['groups'] ?? [];

                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                $exam->setCreator($this->getUser() ? $this->getUser()->getUserIdentifier() : null); // getUserIdentifier() ist moderner als getUsername()
                
                $em->persist($exam);
                $em->flush(); // ID generieren

                $debugLog = ['added' => [], 'skipped' => [], 'errors' => []];

                if (!empty($selectedGroups) && is_array($selectedGroups)) {
                    foreach ($selectedGroups as $groupAccount) {
                        $this->importParticipantsFromGroup($em, $conn, $exam, (string)$groupAccount, $debugLog);
                    }
                }

                // Feedback
                $countAdded = count($debugLog['added']);
                $countErrors = count($debugLog['errors']);
                
                $msg = "Prüfung angelegt. ";
                if ($countAdded > 0) {
                    $msg .= "<strong>$countAdded</strong> Teilnehmer hinzugefügt.";
                } else {
                    $msg .= "<strong>Keine Teilnehmer hinzugefügt.</strong>";
                }

                if ($countErrors > 0) {
                    $msg .= "<br><br><span style='color:red'><strong>$countErrors Fehler:</strong></span><br>" . implode('<br>', $debugLog['errors']);
                    $this->addFlash('warning', $msg); // Warning statt Success bei Fehlern
                } else {
                    $this->addFlash('success', $msg);
                }
               
                return $this->redirectToRoute('app_exams_dashboard');

            } catch (\Throwable $e) {
                $this->addFlash('error', 'Fehler beim Anlegen: ' . $e->getMessage());
            }
        }

        return $this->render('exams/new.html.twig', [
            'groups'  => $groupsForDropdown
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, Connection $conn, EntityManagerInterface $em): Response
    {
        //$this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $examEntity = $em->getRepository(Exam::class)->find($id);
        if (!$examEntity) throw $this->createNotFoundException('Prüfung nicht gefunden');
        
        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);

        // --- POST HANDLING ---
        if ($request->isMethod('POST')) {
            
            // 1. STAMMDATEN
            if ($request->request->has('exam_year')) {
                $name = trim($request->request->get('exam_name'));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;
                $date = $request->request->get('exam_date') ?: null;

                $conn->update('sportabzeichen_exams', [
                    'exam_name' => $name,
                    'exam_year' => $year,
                    'exam_date' => $date
                ], ['id' => $id]);

                $this->addFlash('success', 'Stammdaten gespeichert.');
                return $this->redirectToRoute('app_exams_edit', ['id' => $id, 'q' => $request->query->get('q')]);
            }

            // 2. GRUPPE HINZUFÜGEN
            if ($request->request->has('add_group')) {
                $groupAct = $request->request->get('group_act');
                if ($groupAct) {
                    try {
                        $this->importParticipantsFromGroup($em, $conn, $examEntity, $groupAct);
                        $this->addFlash('success', 'Gruppe hinzugefügt und Mitglieder importiert.');
                    } catch (\Throwable $e) {
                        $this->addFlash('error', 'Fehler beim Import: ' . $e->getMessage());
                    }
                }
                return $this->redirectToRoute('app_exams_edit', ['id' => $id]);
            }

            // 3. GRUPPE ENTFERNEN
            if ($request->request->has('remove_group')) {
                $groupAct = $request->request->get('remove_group');
                
                // FIX: "app_groups" -> "groups"
                $groupId = $conn->fetchOne("SELECT id FROM groups WHERE act = ?", [$groupAct]);
                
                if ($groupId) {
                    $conn->executeStatement(
                        "DELETE FROM sportabzeichen_exam_groups WHERE exam_id = ? AND group_id = ?", 
                        [$id, $groupId]
                    );
                    $this->addFlash('success', 'Gruppe aus der Prüfung entfernt (Teilnehmer bleiben).');
                } else {
                     $this->addFlash('error', 'Gruppe nicht gefunden.');
                }
                return $this->redirectToRoute('app_exams_edit', ['id' => $id]);
            }

            // 4. EINZELNEN TEILNEHMER HINZUFÜGEN
            if ($request->request->has('account')) {
                $account = trim($request->request->get('account', ''));
                $gender  = $request->request->get('gender');
                $dobStr  = $request->request->get('dob');

                if ($account && $gender && $dobStr) {
                    $userId = $conn->fetchOne("SELECT id FROM users WHERE act = :act AND deleted IS NULL", ['act' => $account]);
                    if ($userId) {
                        try {
                            // A) Pool Update/Insert
                            $conn->executeStatement("
                                INSERT INTO sportabzeichen_participants (user_id, geburtsdatum, geschlecht, username)
                                VALUES (?, ?, ?, ?)
                                ON CONFLICT (user_id) DO UPDATE SET 
                                    geburtsdatum = EXCLUDED.geburtsdatum, 
                                    geschlecht = EXCLUDED.geschlecht
                            ", [$userId, $dobStr, $gender, $account]);

                            // B) Prüfung hinzufügen
                            $this->processParticipantByUserId($conn, (int)$id, (int)$exam['exam_year'], (int)$userId);
                            
                            $this->addFlash('success', "Teilnehmer hinzugefügt.");
                        } catch (\Throwable $e) {
                            $this->addFlash('error', 'Fehler: ' . $e->getMessage());
                        }
                    }
                }
                return $this->redirectToRoute('app_exams_edit', ['id' => $id, 'q' => $request->query->get('q')]);
            }
        }

        // --- GET DATEN ---

        // A) Zugeordnete Gruppen
        // FIX: "app_groups" -> "groups"
        $sqlGroups = "
            SELECT g.act, g.name 
            FROM sportabzeichen_exam_groups seg 
            JOIN groups g ON seg.group_id = g.id
            WHERE seg.exam_id = ? 
            ORDER BY g.name ASC
        ";
        $groupResults = $conn->fetchAllAssociative($sqlGroups, [$id]); 
        
        $assignedActs = array_column($groupResults, 'act');

        // B) Verfügbare Gruppen
        $allGroupsObj = $em->getRepository(Group::class)->findBy([], ['name' => 'ASC']);
        $availableGroups = [];
        
        $assignedMap = array_flip($assignedActs);

        foreach ($allGroupsObj as $g) {
            $gAct = $g->getAct(); 
            if (!isset($assignedMap[$gAct])) {
                $availableGroups[$gAct] = $g->getName(); 
            }
        }

        // C) Liste der fehlenden Schüler
        $searchTerm = trim($request->query->get('q', ''));
        $missingStudents = [];

        // FIX: "app_groups" -> "groups"
        $sql = "
            SELECT DISTINCT
                u.id, u.act, u.firstname, u.lastname,
                sp.geburtsdatum, sp.geschlecht as sp_gender,
                g.name as group_name,
                (sp.geburtsdatum IS NULL) as is_missing_dob
            FROM users u
            INNER JOIN members m ON u.act = m.actuser
            
            -- Brücke: members (String) -> groups (ID) -> exam_groups (ID)
            INNER JOIN groups g ON m.actgrp = g.act 
            INNER JOIN sportabzeichen_exam_groups seg ON g.id = seg.group_id
            
            LEFT JOIN sportabzeichen_participants sp ON u.id = sp.user_id
            
            WHERE u.deleted IS NULL
            AND seg.exam_id = :examId
            
            AND (
                NOT EXISTS (
                    SELECT 1 FROM sportabzeichen_exam_participants sep
                    JOIN sportabzeichen_participants sp_inner ON sep.participant_id = sp_inner.id
                    WHERE sp_inner.user_id = u.id AND sep.exam_id = :examId
                )
                OR
                (sp.geburtsdatum IS NULL OR sp.geschlecht IS NULL OR sp.geschlecht = '')
            )
        ";

        $params = ['examId' => $id];

        if (!empty($searchTerm)) {
            $sql .= " AND (u.lastname ILIKE :search OR u.firstname ILIKE :search) ";
            $params['search'] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY is_missing_dob DESC, u.lastname ASC, u.firstname ASC LIMIT 300";

        $rows = $conn->fetchAllAssociative($sql, $params);

        foreach ($rows as $row) {
            $missingStudents[] = [
                'account'   => $row['act'],
                'name'      => $row['firstname'] . ' ' . $row['lastname'],
                'dob'       => $row['geburtsdatum'],
                'gender'    => $row['sp_gender'] ?? 'MALE',
                'group'     => $row['group_name']
            ];
        }

        return $this->render('exams/edit.html.twig', [
            'exam' => $exam,
            'assigned_groups' => $groupResults, 
            'available_groups' => $availableGroups,
            'missing_students' => $missingStudents,
            'search_term' => $searchTerm
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request, Connection $conn): Response
    {
        //$this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $id, $token)) {
            $this->addFlash('error', 'Ungültiger Token.');
            return $this->redirectToRoute('app_exams_dashboard');
        }

        $conn->beginTransaction();
        try {
            // 1. Ergebnisse löschen
            $conn->executeStatement("
                DELETE FROM sportabzeichen_exam_results 
                WHERE ep_id IN (SELECT id FROM sportabzeichen_exam_participants WHERE exam_id = ?)
            ", [$id]);

            // 2. Teilnehmer-Verknüpfungen
            $conn->executeStatement("DELETE FROM sportabzeichen_exam_participants WHERE exam_id = ?", [$id]);
            
            // 3. Gruppen-Verknüpfungen (ManyToMany Table)
            $conn->executeStatement("DELETE FROM sportabzeichen_exam_groups WHERE exam_id = ?", [$id]);

            // 4. Prüfung
            $conn->executeStatement("DELETE FROM sportabzeichen_exams WHERE id = ?", [$id]);

            $conn->commit();
            $this->addFlash('success', 'Prüfung gelöscht.');

        } catch (\Exception $e) {
            $conn->rollBack();
            $this->addFlash('error', 'Fehler: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_exams_dashboard');
    }

    private function importParticipantsFromGroup(
        EntityManagerInterface $em, 
        Connection $conn, 
        Exam $exam, 
        string $groupId, 
        array &$debugLog = []
    ): void {
        // 1. Gruppe finden
        $groupEntity = $em->getRepository(Group::class)->find($groupId);

        if (!$groupEntity) {
            $debugLog['errors'][] = "Gruppe mit ID '$groupId' nicht gefunden.";
            return;
        }

        // Relation zwischen Prüfung und Gruppe speichern
        // Symfony nutzt hierfür laut deiner Liste: sportabzeichen_exam_groups
        $exam->addGroup($groupEntity);
        $em->persist($exam);
        $em->flush(); 

        // 2. Mitglieder der Gruppe laden
        // Wir nutzen jetzt den Tabellennamen aus deinem Terminal: users_groups
        $sql = "
            SELECT u.id, u.firstname, u.lastname
            FROM users u
            INNER JOIN users_groups ug ON u.id = ug.user_id
            WHERE ug.group_id = ?
        ";
        
        $users = $conn->fetchAllAssociative($sql, [$groupId]);

        if (empty($users)) {
            $debugLog['skipped'][] = "Gruppe '" . $groupEntity->getName() . "' (ID $groupId) ist leer oder hat keine verknüpften User.";
            return;
        }

        foreach ($users as $row) {
            $this->processParticipantByUserId($conn, $exam->getId(), $exam->getYear(), (int)$row['id']);
            $debugLog['added'][] = $row['firstname'] . ' ' . $row['lastname'];
        }
    }

    private function processParticipantByUserId(Connection $conn, int $examId, int $examYear, int $userId): void
    {
        // 1. Participant im Pool suchen
        $poolData = $conn->fetchAssociative(
            "SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE user_id = ?", 
            [$userId]
        );
        
        if ($poolData) {
            $participantId = $poolData['id'];
            $dob = $poolData['geburtsdatum'];
        } else {
            // Falls der User noch nie im Sport-Pool war: Minimal-Eintrag anlegen
            $conn->executeStatement("
                INSERT INTO sportabzeichen_participants (user_id, updated_at) 
                VALUES (?, CURRENT_TIMESTAMP)
            ", [$userId]);
            $participantId = $conn->lastInsertId();
            $dob = null;
        }

        // 2. Alter berechnen (Sport-Regel: Jahr - Jahr)
        $age = 0;
        if ($dob) {
            $birthYear = (int)date('Y', strtotime((string)$dob));
            $age = $examYear - $birthYear;
        }

        // 3. In die Prüfung (Exam) eintragen
        $conn->executeStatement("
            INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
            VALUES (?, ?, ?)
            ON CONFLICT (exam_id, participant_id) 
            DO UPDATE SET age_year = EXCLUDED.age_year
        ", [$examId, $participantId, $age]);
    }

    #[Route('/{id}/add_participant', name: 'add_participant', methods: ['GET', 'POST'])]
    public function addParticipant(int $id, Request $request, Connection $conn): Response
    {
        //$this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden');

        // --- POST: Manuell hinzufügen ---
        if ($request->isMethod('POST')) {
            $account = trim($request->request->get('account', ''));
            $gender  = $request->request->get('gender');
            $dobStr  = $request->request->get('dob');

            if ($account && $userId = $conn->fetchOne("SELECT id FROM users WHERE act = ?", [$account])) {
                // Update Pool with form data
                 $conn->executeStatement("
                    INSERT INTO sportabzeichen_participants (user_id, geburtsdatum, geschlecht, username)
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT (user_id) DO UPDATE SET 
                        geburtsdatum = EXCLUDED.geburtsdatum, 
                        geschlecht = EXCLUDED.geschlecht
                ", [$userId, $dobStr, $gender, $account]);

                $this->processParticipantByUserId($conn, (int)$id, (int)$exam['exam_year'], (int)$userId);
                $this->addFlash('success', "Teilnehmer hinzugefügt.");
            }
            return $this->redirectToRoute('app_exams_add_participant', ['id' => $id, 'q' => $request->query->get('q')]);
        }

        // --- GET LISTE ---
        $searchTerm = trim($request->query->get('q', ''));
        $missingStudents = [];

        // FIX: "app_groups" -> "groups"
        $sql = "
            SELECT DISTINCT
                u.id, u.act, u.firstname, u.lastname,
                sp.geburtsdatum, sp.geschlecht as sp_gender
            FROM users u
            JOIN members m ON u.act = m.actuser
            
            -- Brücke: members(String) -> groups(ID) -> exam_groups(ID)
            JOIN groups g ON m.actgrp = g.act
            JOIN sportabzeichen_exam_groups seg ON g.id = seg.group_id
            
            LEFT JOIN sportabzeichen_participants sp ON u.id = sp.user_id
            
            WHERE u.deleted IS NULL
            AND seg.exam_id = :examId
            
            AND NOT EXISTS (
                SELECT 1 FROM sportabzeichen_exam_participants sep
                JOIN sportabzeichen_participants sp_inner ON sep.participant_id = sp_inner.id
                WHERE sp_inner.user_id = u.id AND sep.exam_id = :examId
            )
        ";

        $params = ['examId' => $id];

        if (!empty($searchTerm)) {
            $sql .= " AND (u.lastname ILIKE :search OR u.firstname ILIKE :search) ";
            $params['search'] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY (sp.geburtsdatum IS NULL) DESC, u.lastname ASC, u.firstname ASC LIMIT 500";

        $rows = $conn->fetchAllAssociative($sql, $params);

        foreach ($rows as $row) {
            $missingStudents[] = [
                'account'   => $row['act'],
                'name'      => $row['firstname'] . ' ' . $row['lastname'],
                'dob'       => $row['geburtsdatum'],
                'gender'    => $row['sp_gender'] ?? 'MALE'
            ];
        }

        return $this->render('exams/add_participant.html.twig', [
            'exam' => $exam,
            'missing_students' => $missingStudents,
            'search_term' => $searchTerm
        ]);
    }

    #[Route('/{id}/stats', name: 'stats', methods: ['GET'])]
    public function stats(int $id, Connection $conn): Response
    {
        //$this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // 1. Prüfungsdaten laden
        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden');

        // 2. Punkte pro Teilnehmer berechnen
        $sqlPoints = "
            SELECT 
                ep.id,
                u.firstname, u.lastname,
                SUM(COALESCE(r.points, 0)) as total_points
            FROM sportabzeichen_exam_participants ep
            JOIN sportabzeichen_participants p ON ep.participant_id = p.id
            JOIN users u ON p.user_id = u.id
            LEFT JOIN sportabzeichen_exam_results r ON ep.id = r.ep_id
            WHERE ep.exam_id = :id
            GROUP BY ep.id, u.lastname, u.firstname
        ";
        
        // KORREKTUR: Hier fehlte das Array ['id' => $id]
        $participants = $conn->fetchAllAssociative($sqlPoints, ['id' => $id]);

        // Statistik berechnen
        $stats = [
            'Gold' => 0,
            'Silber' => 0,
            'Bronze' => 0,
            'Ohne' => 0
        ];

        foreach ($participants as $p) {
            $pts = (int)$p['total_points'];
            if ($pts >= 11) {
                $stats['Gold']++;
            } elseif ($pts >= 8) {
                $stats['Silber']++;
            } elseif ($pts >= 4) {
                $stats['Bronze']++;
            } else {
                $stats['Ohne']++;
            }
        }

        // 3. Top 10 pro Disziplin laden
        $sqlResults = "
            SELECT 
                d.name as discipline_name,
                d.berechnungsart,  -- NEU: Wichtig für die Sortierung!
                d.einheit,         -- NEU: Hilfreich für die Anzeige (m, s, min)
                r.leistung as value,
                r.points,
                u.firstname, 
                u.lastname,
                p.geburtsdatum, 
                p.geschlecht,
                
                -- Subquery für Gruppen (verhindert doppelte Zeilen)
                (
                    SELECT STRING_AGG(DISTINCT g_sub.name, ', ')
                    FROM app_groups g_sub
                    -- FIX 1: Typ-Umwandlung (CAST) für den Vergleich
                    JOIN members m_sub ON CAST(g_sub.id AS VARCHAR) = m_sub.actgrp
                    -- FIX 2: Auch hier User-ID als Text vergleichen, falls u.act alt ist
                    WHERE m_sub.actuser = CAST(u.id AS VARCHAR)
                    -- FIX 3: Spaltennamen anpassen (id statt act, group_id statt act)
                    AND g_sub.id IN (SELECT group_id FROM sportabzeichen_exam_groups WHERE exam_id = :id)
                ) as group_name

            FROM sportabzeichen_exam_results r
            JOIN sportabzeichen_disciplines d ON r.discipline_id = d.id
            JOIN sportabzeichen_exam_participants ep ON r.ep_id = ep.id
            JOIN sportabzeichen_participants p ON ep.participant_id = p.id
            JOIN users u ON p.user_id = u.id
            
            WHERE ep.exam_id = :id AND r.points > 0
            
            -- Grobe Vorsortierung (Fein-Sortierung macht PHP gleich)
            ORDER BY d.name ASC, r.points DESC
        ";

        $allResults = $conn->fetchAllAssociative($sqlResults, ['id' => $id]);

        // ... (Jahresberechnung $examYear bleibt gleich) ...
        $examYear = (int)date('Y');
        if (!empty($exam['exam_year'])) {
            $examYear = (int)$exam['exam_year'];
        } elseif (!empty($exam['exam_date'])) {
            $examYear = (int)(new \DateTime($exam['exam_date']))->format('Y');
        }

        // Struktur aufbauen
        $topList = [];

        foreach ($allResults as $row) {
            $disc = $row['discipline_name'];
            
            // Geschlecht
            $dbGeschlecht = $row['geschlecht'];
            $genderKey = ($dbGeschlecht === 'MALE') ? 'Männlich' : (($dbGeschlecht === 'FEMALE') ? 'Weiblich' : 'Divers');

            // Altersklasse
            $akKey = 'Unbekannt';
            if (!empty($row['geburtsdatum'])) {
                try {
                    $birthYear = (int)(new \DateTime($row['geburtsdatum']))->format('Y');
                    $age = $examYear - $birthYear;
                    $akKey = 'AK ' . $age; 
                } catch (\Exception $e) {}
            }

            if (!isset($topList[$disc])) {
                $topList[$disc] = ['Männlich' => [], 'Weiblich' => [], 'Divers' => []];
            }
            if (!isset($topList[$disc][$genderKey][$akKey])) {
                $topList[$disc][$genderKey][$akKey] = [];
            }

            $topList[$disc][$genderKey][$akKey][] = $row;
        }

        // SORTIERUNG & FILTERUNG
        foreach ($topList as $disc => $genders) {
            foreach ($genders as $gender => $aks) {
                if (empty($aks)) {
                    unset($topList[$disc][$gender]);
                    continue;
                }

                foreach ($aks as $ak => $rows) {
                    usort($rows, function ($a, $b) {
                        // 1. PUNKTE vergleichen (immer absteigend: 3 ist besser als 1)
                        if ($a['points'] !== $b['points']) {
                            return $b['points'] <=> $a['points'];
                        }

                        // 2. WERT vergleichen (nur wenn Punkte gleich sind)
                        // Hier kommt 'berechnungsart' ins Spiel
                        $type = $a['berechnungsart']; // z.B. 'GREATER' oder 'LESS'
                        
                        // Fall A: 'GREATER' (Weitsprung etc.) -> Absteigend sortieren
                        if ($type === 'BIGGER') {
                            return $b['value'] <=> $a['value'];
                        } 
                        
                        // Fall B: 'LESS' (Laufen, Schwimmen etc.) -> Aufsteigend sortieren
                        // (Kleinerer Wert ist besser)
                        return $a['value'] <=> $b['value'];
                    });

                    // Top 10 beschneiden
                    $topList[$disc][$gender][$ak] = array_slice($rows, 0, 10);
                }
                uksort($topList[$disc][$gender], 'strnatcmp');
            }
        }

        return $this->render('exams/stats.html.twig', [
            'exam' => $exam,
            'stats' => $stats,
            'topList' => $topList,
            'totalParticipants' => count($participants)
        ]);
    }
}