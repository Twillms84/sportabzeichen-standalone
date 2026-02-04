<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\Group;
use App\Entity\Exam;
use App\Repository\ExamRepository;
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

        // 1. Alle Prüfungen laden (als Array für einfache Handhabung)
        $exams = $conn->fetchAllAssociative("
            SELECT * FROM sportabzeichen_exams 
            ORDER BY exam_year DESC, exam_date DESC
        ");

        // 2. Performance-Query: Punkte aller Teilnehmer aller Prüfungen auf einmal holen
        // Wir gruppieren nach Prüfung und Teilnehmer und summieren die Punkte
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

        // 3. Daten aggregieren nach Jahr
        $yearlyStats = [];

        // Schritt A: Struktur vorbereiten basierend auf den Exams
        foreach ($exams as $exam) {
            $year = $exam['exam_year'];
            
            if (!isset($yearlyStats[$year])) {
                $yearlyStats[$year] = [
                    'year' => $year,
                    'exams' => [],
                    'stats' => ['Gold' => 0, 'Silber' => 0, 'Bronze' => 0, 'Ohne' => 0, 'Total' => 0],
                    'unique_users' => [] // Um Teilnehmer pro Jahr eindeutig zu zählen
                ];
            }
            $yearlyStats[$year]['exams'][] = $exam;
        }

        // Schritt B: Ergebnisse zuordnen
        foreach ($rawResults as $row) {
            $year = $row['exam_year'];
            
            // Falls Prüfung existiert (sollte immer so sein)
            if (isset($yearlyStats[$year])) {
                $pts = (int)$row['total_points'];
                
                // Teilnehmer zählen (pro Jahr eindeutig machen durch user_id als Key)
                $yearlyStats[$year]['unique_users'][$row['user_id']] = true;

                // Medaillen berechnen (Logik analog zu deiner stats-Methode)
                if ($pts >= 11) {
                    $yearlyStats[$year]['stats']['Gold']++;
                    $yearlyStats[$year]['stats']['Total']++;
                } elseif ($pts >= 8) {
                    $yearlyStats[$year]['stats']['Silber']++;
                    $yearlyStats[$year]['stats']['Total']++;
                } elseif ($pts >= 4) {
                    $yearlyStats[$year]['stats']['Bronze']++;
                    $yearlyStats[$year]['stats']['Total']++;
                } else {
                    $yearlyStats[$year]['stats']['Ohne']++;
                }
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

        // Gruppen laden (Für Dropdown)
        $groupRepo = $em->getRepository(Group::class);
        $allGroups = $groupRepo->findBy([], ['name' => 'ASC']);
        
        $groupsForDropdown = [];
        foreach ($allGroups as $g) {
            $acc = $g->getAccount();
            if ($acc) {
                // Key = Account (klasse.5a), Value = Name (Klasse 5a)
                $groupsForDropdown[$acc] = $g->getName();
            }
        }

        if ($request->isMethod('POST')) {
            try {
                $name = trim($request->request->get('exam_name', ''));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;
                
                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;
                
                // Holen der Gruppen. WICHTIG: Im HTML muss name="groups[]" stehen!
                $postData = $request->request->all();
                $selectedGroups  = $postData['groups'] ?? [];

                // --- DEBUG: Falls immer noch nichts passiert, Zeile einkommentieren ---
                // dd($selectedGroups); 

                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                $exam->setCreator($this->getUser() ? $this->getUser()->getUsername() : null);
                
                $em->persist($exam);
                $em->flush();

                // --- DEBUGGING LISTE INITIALISIEREN ---
                $debugLog = [
                    'added' => [], 
                    'skipped' => [],
                    'errors' => [] // <--- Das hier hat gefehlt!
                ];

                // Gruppen importieren
                if (!empty($selectedGroups) && is_array($selectedGroups)) {
                    foreach ($selectedGroups as $groupAccount) {
                        $groupAccount = (string)$groupAccount;
                        // Ruft die neue SQL-basierte Methode auf
                        $this->importParticipantsFromGroup($em, $conn, $exam, $groupAccount, $debugLog);
                    }
                }

                // --- FEEDBACK MELDUNG BAUEN ---
                $countAdded = count($debugLog['added']);
                $countErrors = count($debugLog['errors']);
                
                $msg = "Prüfung angelegt. ";
                
                if ($countAdded > 0) {
                    $msg .= "<strong>$countAdded</strong> Teilnehmer hinzugefügt. ";
                    $names = array_slice($debugLog['added'], 0, 5);
                    $msg .= "(z.B. " . implode(', ', $names) . ")";
                } else {
                    $msg .= "<strong>Keine Teilnehmer hinzugefügt.</strong>";
                }

                // WICHTIG: Fehler anzeigen!
                if ($countErrors > 0) {
                    $msg .= "<br><br><span style='color:red'><strong>$countErrors Fehler/Warnungen:</strong></span><br>";
                    $msg .= implode('<br>', $debugLog['errors']);
                }

                if ($countAdded > 0) {
                     $this->addFlash('success', $msg);
                } else {
                     // Wenn niemand hinzugefügt wurde, eher eine Warnung zeigen
                     $this->addFlash('warning', $msg);
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

        // Wir brauchen das Entity für die Helper-Methode importParticipantsFromGroup
        $examEntity = $em->getRepository(Exam::class)->find($id);
        if (!$examEntity) throw $this->createNotFoundException('Prüfung nicht gefunden');
        
        // Array-Daten für DBAL-Operationen
        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);

        // --- POST HANDLING ---
        if ($request->isMethod('POST')) {
            
            // 1. STAMMDATEN BEARBEITEN
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
                $conn->executeStatement(
                    "DELETE FROM sportabzeichen_exam_groups WHERE exam_id = ? AND act = ?", 
                    [$id, $groupAct]
                );
                $this->addFlash('success', 'Gruppe aus der Prüfung entfernt (bereits importierte Teilnehmer bleiben erhalten).');
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
                            // A) Pool-Daten updaten/anlegen
                            $existingPartId = $conn->fetchOne(
                                "SELECT id FROM sportabzeichen_participants WHERE user_id = ?", 
                                [$userId]
                            );

                            if ($existingPartId) {
                                $conn->update('sportabzeichen_participants', [
                                    'geburtsdatum' => $dobStr,
                                    'geschlecht' => $gender
                                ], ['id' => $existingPartId]);
                            } else {
                                $conn->insert('sportabzeichen_participants', [
                                    'user_id' => $userId,
                                    'geburtsdatum' => $dobStr,
                                    'geschlecht' => $gender,
                                    'username' => $account
                                ]);
                            }

                            // B) In Prüfung einfügen
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

        // --- GET DATEN LADEN ---

        // A) Zugeordnete Gruppen
        $sqlGroups = "
            SELECT seg.group_id as act, g.name 
            FROM sportabzeichen_exam_groups seg 
            LEFT JOIN app_groups g ON seg.group_id = g.id
            WHERE seg.exam_id = ? 
            ORDER BY g.name ASC
        ";
        
        // WICHTIG: Hier führen wir den SQL-Befehl erst aus!
        $groupResults = $conn->fetchAllAssociative($sqlGroups, [$id]); 

        // Jetzt holen wir die IDs aus dem Datenbank-Ergebnis
        $assignedActs = array_column($groupResults, 'act');

        // B) Verfügbare Gruppen
        $allGroupsObj = $em->getRepository(Group::class)->findBy([], ['name' => 'ASC']);
        $availableGroups = [];
        
        foreach ($allGroupsObj as $g) {
            // ACHTUNG: Wir vergleichen hier IDs. 
            // Falls $assignedActs IDs enthält (was es tut), müssen wir $g->getId() prüfen!
            if (!in_array($g->getId(), $assignedActs)) {
                // Hier kannst du entscheiden, ob du die ID oder den Account als Key willst
                // Ich nehme mal getId(), das ist sicherer für neue Einträge
                $availableGroups[$g->getId()] = $g->getName();
            }
        }

        // C) Liste der fehlenden Schüler laden
        $searchTerm = trim($request->query->get('q', ''));
        $missingStudents = [];

        $sql = "
            SELECT DISTINCT
                u.id, u.act, u.firstname, u.lastname,
                sp.geburtsdatum, sp.geschlecht as sp_gender,
                g.name as group_name,
                (sp.geburtsdatum IS NULL) as is_missing_dob
            FROM users u
            INNER JOIN members m ON u.act = m.actuser
            
            -- FIX: String (m.actgrp) in ID wandeln via app_groups
            INNER JOIN app_groups g ON m.actgrp = CAST(g.id AS VARCHAR)
            
            -- FIX: Korrekter Spaltenname group_id statt act
            INNER JOIN sportabzeichen_exam_groups seg ON g.id = seg.group_id 
            
            LEFT JOIN sportabzeichen_participants sp ON u.id = sp.user_id
            
            WHERE u.deleted IS NULL
            AND seg.exam_id = :examId
            
            AND (
                -- FALL 1: Schüler nimmt noch gar nicht teil
                NOT EXISTS (
                    SELECT 1 FROM sportabzeichen_exam_participants sep
                    JOIN sportabzeichen_participants sp_inner ON sep.participant_id = sp_inner.id
                    WHERE sp_inner.user_id = u.id AND sep.exam_id = :examId
                )
                OR
                -- FALL 2: Schüler nimmt schon teil, hat aber KEIN Geburtsdatum
                (sp.geburtsdatum IS NULL OR sp.geschlecht IS NULL OR sp.geschlecht = '')
            )
            ORDER BY is_missing_dob DESC, u.lastname ASC, u.firstname ASC LIMIT 300
        ";

        $params = ['examId' => $id];

        if (!empty($searchTerm)) {
            $sql .= " AND (u.lastname ILIKE :search OR u.firstname ILIKE :search) ";
            $params['search'] = '%' . $searchTerm . '%';
        }

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

        return $this->render('app_exams/edit.html.twig', [
            'exam' => $exam,
            'assigned_groups' => $assignedGroups,
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
            $this->addFlash('error', 'Ungültiger Sicherheits-Token.');
            return $this->redirectToRoute('app_exams_dashboard');
        }

        $conn->beginTransaction();
        try {
            // 1. Ergebnisse löschen
            $conn->executeStatement("
                DELETE FROM sportabzeichen_exam_results 
                WHERE ep_id IN (SELECT id FROM sportabzeichen_exam_participants WHERE exam_id = ?)
            ", [$id]);

            // 2. Teilnehmer-Verknüpfungen löschen
            $conn->executeStatement("DELETE FROM sportabzeichen_exam_participants WHERE exam_id = ?", [$id]);
            
            // 3. Gruppen-Verknüpfungen löschen (Neu, der Sauberkeit halber)
            $conn->executeStatement("DELETE FROM sportabzeichen_exam_groups WHERE exam_id = ?", [$id]);

            // 4. Prüfung selbst löschen
            $conn->executeStatement("DELETE FROM sportabzeichen_exams WHERE id = ?", [$id]);

            $conn->commit();
            $this->addFlash('success', 'Prüfung gelöscht.');

        } catch (\Exception $e) {
            $conn->rollBack();
            $this->addFlash('error', 'Fehler beim Löschen: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_exams_dashboard');
    }

    private function importParticipantsFromGroup(
        EntityManagerInterface $em, 
        Connection $conn, 
        Exam $exam, 
        string $groupAccount, 
        array &$debugLog = []
    ): void
    {
        // --- SCHRITT 1: Gruppe sauber über Doctrine verknüpfen ---
        
        // Wir suchen die Gruppe anhand ihres Accounts (z.B. "klasse.5a")
        $groupEntity = $em->getRepository(Group::class)->findOneBy(['account' => $groupAccount]);

        if (!$groupEntity) {
            $debugLog['errors'][] = "Gruppe mit Account '$groupAccount' nicht in der Datenbank gefunden.";
            return;
        }

        // Wir nutzen die Entity-Methode -> Doctrine kümmert sich um die Tabelle 'sportabzeichen_exam_groups'
        $exam->addGroup($groupEntity);
        $em->persist($exam);
        $em->flush(); // Schreibt die Verknüpfung in die DB


        // --- SCHRITT 2: Schüler importieren (Das bleibt SQL, da IServ-Struktur) ---

        $sql = "
            SELECT u.id, u.act, u.firstname, u.lastname
            FROM users u
            JOIN members m ON u.act = m.actuser
            WHERE m.actgrp = ?
            AND u.deleted IS NULL -- WICHTIG: Gelöschte User ignorieren
        ";

        $users = $conn->fetchAllAssociative($sql, [$groupAccount]);

        if (empty($users)) {
            // Hinweis: Das ist kein Fehler, leere Klassen gibt es manchmal
            $debugLog['skipped'][] = "Gruppe '$groupAccount' hat keine Mitglieder.";
            return;
        }

        // 3. User iterieren
        foreach ($users as $row) {
            $realUserId = $row['id'];
            $accountName = $row['act'];
            $displayName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: $accountName;

            // --- SCHRITT A: Pool-Eintrag prüfen ---
            $poolData = $conn->fetchAssociative("SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE user_id = ?", [$realUserId]);

            $participantId = null;
            $dobString = null;

            if ($poolData) {
                $participantId = $poolData['id'];
                $dobString = $poolData['geburtsdatum'];
                // Update Username zur Sicherheit
                $conn->executeStatement("UPDATE sportabzeichen_participants SET username = ? WHERE id = ?", [$accountName, $participantId]);
            } else {
                // NEU anlegen
                $conn->executeStatement("
                    INSERT INTO sportabzeichen_participants (user_id, username) VALUES (?, ?)
                ", [$realUserId, $accountName]);
                $participantId = $conn->fetchOne("SELECT id FROM sportabzeichen_participants WHERE user_id = ?", [$realUserId]);
            }

            if (!$participantId) continue;

            // --- SCHRITT B: In Prüfung eintragen ---
            $age = 0;
            if ($dobString) {
                $birthYear = (int)substr((string)$dobString, 0, 4);
                $age = $exam->getYear() - $birthYear;
            }

            try {
                // Hier fangen wir Unique Constraint Fehler ab
                $conn->executeStatement("
                    INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                    VALUES (?, ?, ?)
                    ON CONFLICT (exam_id, participant_id) DO NOTHING
                ", [$exam->getId(), $participantId, $age]);
                
                // Wir zählen nur als "added", wenn er nicht schon da war? 
                // DBAL gibt bei 'DO NOTHING' oft trotzdem 0 oder 1 zurück, je nach Treiber.
                // Einfachheitshalber loggen wir es hier:
                $debugLog['added'][] = $displayName;
                
            } catch (\Exception $e) {
                $debugLog['errors'][] = "Fehler bei $displayName: " . $e->getMessage();
            }
        }
    }

    private function processParticipantByUserId(Connection $conn, int $examId, int $examYear, int $userId): void
    {
        // 1. Prüfen, ob User schon im Pool ist, und Geburtsdatum holen
        $participantId = null;
        $dob = null;

        $poolData = $conn->fetchAssociative("SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
        
        if ($poolData) {
            $participantId = $poolData['id'];
            if (!empty($poolData['geburtsdatum'])) {
                $dob = $poolData['geburtsdatum'];
            }
        } else {
            // User ist noch gar nicht im Pool -> Anlegen! (Ohne Datum)
            $conn->executeStatement("INSERT INTO sportabzeichen_participants (user_id) VALUES (?)", [$userId]);
            $participantId = $conn->fetchOne("SELECT id FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
        }

        // 2. Fallback: System-Daten prüfen (Nur wenn wir noch kein Datum haben)
        if (!$dob) {
            try {
                // Da deine Tabelle 'users' keine birthday-Spalte hat, wird das hier in den catch laufen
                // oder null zurückgeben. Wir lassen es drin für die Zukunft/Kompatibilität.
                $sysData = $conn->fetchAssociative("SELECT birthday FROM users WHERE id = ?", [$userId]);
                
                if ($sysData && !empty($sysData['birthday'])) {
                    $dob = $sysData['birthday'];
                    // Gefundenes Datum sofort im Pool speichern
                    $conn->executeStatement("UPDATE sportabzeichen_participants SET geburtsdatum = ? WHERE id = ?", [$dob, $participantId]);
                }
            } catch (\Throwable $e) {
                // Spalte existiert nicht -> Ignorieren.
            }
        }

        // WICHTIG: Hier NICHT abbrechen, auch wenn $dob leer ist!

        // 3. Alter berechnen (0 falls unbekannt)
        $age = 0;
        if ($dob) {
            $birthYear = (int)substr((string)$dob, 0, 4);
            $age = $examYear - $birthYear;
        }

        // 4. In Prüfung eintragen
        // Da wir oben sichergestellt haben, dass $participantId existiert, können wir jetzt inserten.
        if ($participantId) {
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?)
                ON CONFLICT (exam_id, participant_id) 
                DO UPDATE SET age_year = EXCLUDED.age_year
            ", [$examId, $participantId, $age]);
        }
    }

    // --- Add Participant ---
    #[Route('/{id}/add_participant', name: 'add_participant', methods: ['GET', 'POST'])]
    public function addParticipant(int $id, Request $request, Connection $conn): Response
    {
        //$this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden');

        // --- POST: User manuell hinzufügen ---
        if ($request->isMethod('POST')) {
            $account = trim($request->request->get('account', ''));
            $gender  = $request->request->get('gender');
            $dobStr  = $request->request->get('dob');

            if ($account && $gender && $dobStr) {
                $userId = $conn->fetchOne("SELECT id FROM users WHERE act = :act AND deleted IS NULL", ['act' => $account]);
                
                if ($userId) {
                    try {
                        $this->processParticipantByUserId($conn, (int)$id, (int)$exam['exam_year'], (int)$userId);
                        // Falls wir ein manuelles Update des Datums brauchen, müsste man das hier erweitern, 
                        // aber processParticipantByUserId verlässt sich auf DB-Daten.
                        // Wenn der User im Formular ein Datum angibt, wollen wir das ggf. in den Pool schreiben:
                        
                        $conn->executeStatement("
                            INSERT INTO sportabzeichen_participants (user_id, geburtsdatum, geschlecht, username)
                            VALUES (?, ?, ?, ?)
                            ON CONFLICT (user_id) DO UPDATE SET 
                                geburtsdatum = EXCLUDED.geburtsdatum, 
                                geschlecht = EXCLUDED.geschlecht,
                                username = EXCLUDED.username
                        ", [$userId, $dobStr, $gender, $account]); // <--- $account am Ende hinzufügen!

                        // Nochmal prozessieren, damit er ins Exam kommt
                        $this->processParticipantByUserId($conn, (int)$id, (int)$exam['exam_year'], (int)$userId);
                        
                        $this->addFlash('success', "Teilnehmer hinzugefügt.");
                    } catch (\Throwable $e) {
                        $this->addFlash('error', 'Fehler: ' . $e->getMessage());
                    }
                }
            }
            return $this->redirectToRoute('app_exams_add_participant', [
                'id' => $id, 
                'q' => $request->query->get('q')
            ]);
        }

        // --- GET: Liste laden ---
        
        $searchTerm = trim($request->query->get('q', ''));
        $missingStudents = [];

        // SQL: Nur User laden, die in einer zugeordneten Gruppe sind (Klasse auxinfo komplett entfernt)
        $sql = "
            SELECT DISTINCT
                u.id, u.act, u.firstname, u.lastname,
                sp.geburtsdatum, sp.geschlecht as sp_gender
            FROM users u
            INNER JOIN members m ON u.act = m.user
            INNER JOIN sportabzeichen_exam_groups seg ON m.group = seg.act 
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

        // Sortierung: Ohne Geburtsdatum zuerst, dann Nachname
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