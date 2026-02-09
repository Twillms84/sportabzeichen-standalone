<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Exam;
use App\Entity\ExamParticipant;
use App\Entity\Group;
use App\Entity\Participant;
use App\Entity\User;
use App\Repository\ExamParticipantRepository;
use App\Repository\ExamRepository;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/exams', name: 'app_exams_')]
final class ExamController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('/', name: 'dashboard')]
    public function index(ExamRepository $examRepo): Response
    {
        // 1. Institution des eingeloggten Users holen
        $user = $this->getUser();
        $institution = $user ? $user->getInstitution() : null;

        // Sicherheits-Check: Ohne Institution keine Daten anzeigen
        if (!$institution) {
            return $this->render('exams/dashboard.html.twig', [
                'yearlyStats' => [],
            ]);
        }

        // 2. NUR Prüfungen der eigenen Institution laden
        // Das ist der wichtigste Teil:
        $exams = $examRepo->findBy(
            ['institution' => $institution], // <--- FILTER HINZUGEFÜGT
            ['year' => 'DESC', 'date' => 'DESC']
        );

        // 3. Daten aggregieren
        $yearlyStats = [];

        foreach ($exams as $exam) {
            $year = $exam->getYear();
            
            if (!isset($yearlyStats[$year])) {
                $yearlyStats[$year] = [
                    'year' => $year,
                    'exams' => [],
                    'stats' => ['Gold' => 0, 'Silber' => 0, 'Bronze' => 0, 'Ohne' => 0, 'Total' => 0],
                    'unique_users' => [] 
                ];
            }
            
            $yearlyStats[$year]['exams'][] = [
                'id' => $exam->getId(),
                'exam_name' => $exam->getName(),
                'exam_date' => $exam->getDate() ? $exam->getDate()->format('Y-m-d') : null,
                'creator' => $exam->getCreator(),
            ];

            // Statistiken berechnen
            foreach ($exam->getExamParticipants() as $ep) {
                // Vorsicht: Null-Check, falls durch Import-Fehler User fehlt
                $participant = $ep->getParticipant();
                if (!$participant || !$participant->getUser()) {
                    continue; 
                }

                $userId = $participant->getUser()->getId();
                
                // User pro Jahr nur einmal zählen für Total
                if (!isset($yearlyStats[$year]['unique_users'][$userId])) {
                     $yearlyStats[$year]['stats']['Total']++;
                     $yearlyStats[$year]['unique_users'][$userId] = true;
                }

                // Punkte auswerten
                // HINWEIS: Methode getTotalPoints() muss in ExamParticipant existieren!
                $pts = $ep->getTotalPoints(); 
                
                if ($pts >= 11) $yearlyStats[$year]['stats']['Gold']++;
                elseif ($pts >= 8) $yearlyStats[$year]['stats']['Silber']++;
                elseif ($pts >= 4) $yearlyStats[$year]['stats']['Bronze']++;
                else $yearlyStats[$year]['stats']['Ohne']++;
            }
        }

        return $this->render('exams/dashboard.html.twig', [
            'yearlyStats' => $yearlyStats,
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, GroupRepository $groupRepo): Response
    {
        // 1. Institution holen (Wichtig für Filter & Speichern)
        $user = $this->getUser();
        $institution = null;
        if ($user && method_exists($user, 'getInstitution')) {
            $institution = $user->getInstitution();
        }

        // Falls keine Institution da ist, Abbruch (sonst Crash)
        if (!$institution) {
            $this->addFlash('error', 'Fehler: Deinem Benutzer ist keine Institution zugewiesen.');
            return $this->redirectToRoute('app_exams_dashboard'); // Oder wohin du willst
        }

        // 2. Gruppen laden 
        // OPTIONAL: Wenn du nur DEINE Gruppen sehen willst, musst du filtern.
        // Da deine Group-Entity (laut letztem Stand) keine 'institution_id' hat, 
        // ist das Filtern schwer. Ich lasse es erstmal auf findAll(), 
        // aber langfristig brauchst du 'institution_id' auch in der Group-Tabelle!
        $allGroups = $groupRepo->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            try {
                $name = trim($request->request->get('exam_name', ''));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;
                
                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;
                $groupIds = $request->request->all()['groups'] ?? [];

                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                $exam->setCreator($user->getUserIdentifier());
                
                // --- HIER IST DER FIX FÜR DEN FEHLER ---
                $exam->setInstitution($institution);
                // ---------------------------------------
                
                $this->em->persist($exam);

                // Gruppen hinzufügen
                $countAdded = 0;
                foreach ($groupIds as $groupId) {
                    $group = $groupRepo->find($groupId); 
                    if ($group) {
                        $exam->addGroup($group);
                        // ACHTUNG: Prüfe unbedingt auch diese Methode "importParticipantsFromGroup"!
                        // Falls die "ExamParticipant" erstellt, muss dort evtl. auch setInstitution rein?
                        // Wenn ExamParticipant keine Institution braucht (weil es am Exam hängt), ist es ok.
                        $countAdded += $this->importParticipantsFromGroup($exam, $group);
                    }
                }

                $this->em->flush();

                $this->addFlash('success', "Prüfung angelegt. $countAdded Teilnehmer hinzugefügt.");
                return $this->redirectToRoute('app_exams_dashboard');

            } catch (\Throwable $e) {
                $this->addFlash('error', 'Fehler beim Anlegen: ' . $e->getMessage());
            }
        }

        // Dropdown Daten vorbereiten
        $groupsForDropdown = [];
        foreach ($allGroups as $g) {
            $groupsForDropdown[$g->getName()] = $g->getId();
        }

        return $this->render('exams/new.html.twig', [
            'groups' => $groupsForDropdown
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, ExamRepository $examRepo, GroupRepository $groupRepo, UserRepository $userRepo): Response
    {
        $exam = $examRepo->find($id);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden');

        // --- POST HANDLING ---
        if ($request->isMethod('POST')) {
            
            // 1. STAMMDATEN SPEICHERN
            if ($request->request->has('exam_year')) {
                $exam->setName(trim($request->request->get('exam_name')));
                $year = (int)$request->request->get('exam_year');
                $exam->setYear($year < 100 ? $year + 2000 : $year);
                $exam->setDate($request->request->get('exam_date') ? new \DateTime($request->request->get('exam_date')) : null);
                
                $this->em->flush();
                $this->addFlash('success', 'Stammdaten gespeichert.');
                return $this->redirectToRoute('app_exams_edit', ['id' => $id, 'q' => $request->query->get('q')]);
            }

            // 2. GRUPPE HINZUFÜGEN
            if ($request->request->has('add_group')) {
                $groupAct = $request->request->get('group_act');
                $group = $groupRepo->findOneBy(['act' => $groupAct]); // Finde Gruppe via 'act'
                
                if ($group) {
                    $exam->addGroup($group);
                    $addedCount = $this->importParticipantsFromGroup($exam, $group);
                    $this->em->flush();
                    $this->addFlash('success', "Gruppe hinzugefügt und $addedCount Mitglieder importiert.");
                }
                return $this->redirectToRoute('app_exams_edit', ['id' => $id]);
            }

            // 3. GRUPPE ENTFERNEN
            if ($request->request->has('remove_group')) {
                $groupAct = $request->request->get('remove_group');
                $group = $groupRepo->findOneBy(['act' => $groupAct]);
                
                if ($group) {
                    $exam->removeGroup($group);
                    $this->em->flush();
                    $this->addFlash('success', 'Gruppe entfernt (Teilnehmer bleiben bestehen).');
                }
                return $this->redirectToRoute('app_exams_edit', ['id' => $id]);
            }

            // 4. EINZELNEN TEILNEHMER HINZUFÜGEN
            if ($request->request->has('account')) {
                $this->handleAddSingleParticipant($request, $exam, $userRepo);
                return $this->redirectToRoute('app_exams_edit', ['id' => $id, 'q' => $request->query->get('q')]);
            }
        }

        // --- VIEW DATEN ---
        // Verfügbare Gruppen filtern
        $assignedGroups = $exam->getGroups();
        $allGroups = $groupRepo->findBy([], ['name' => 'ASC']);
        $availableGroups = [];

        foreach ($allGroups as $g) {
            if (!$assignedGroups->contains($g) && $g->getAct()) {
                $availableGroups[$g->getAct()] = $g->getName();
            }
        }

        // Fehlende Schüler laden (via Repository-Logik)
        $searchTerm = trim($request->query->get('q', ''));
        $missingUsers = $examRepo->findMissingUsersForExam($exam, $searchTerm);
        
        $missingStudentsData = [];
        foreach ($missingUsers as $user) {
            $p = $user->getParticipant();
            $dob = $p ? $p->getGeburtsdatum() : null;
            
            // Gruppennamen sammeln
            $grpNames = array_map(fn($g) => $g->getName(), $user->getGroups()->toArray());

            $missingStudentsData[] = [
                'account' => $user->getAct(),
                'name'    => $user->getFirstname() . ' ' . $user->getLastname(),
                'dob'     => $dob ? $dob->format('Y-m-d') : null,
                'gender'  => $p ? $p->getGeschlecht() : 'MALE',
                'group'   => implode(', ', $grpNames)
            ];
        }

        return $this->render('exams/edit.html.twig', [
            'exam' => [
                'id' => $exam->getId(),
                'name' => $exam->getName(),
                'year' => $exam->getYear(),
                'date' => $exam->getDate() ? $exam->getDate()->format('Y-m-d') : '',
            ],
            'assigned_groups' => array_map(fn($g) => ['act' => $g->getAct(), 'name' => $g->getName()], $assignedGroups->toArray()),
            'available_groups' => $availableGroups,
            'missing_students' => $missingStudentsData,
            'search_term' => $searchTerm
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request, ExamRepository $examRepo): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $id, $token)) {
            $this->addFlash('error', 'Ungültiger Token.');
            return $this->redirectToRoute('app_exams_dashboard');
        }

        $exam = $examRepo->find($id);
        if ($exam) {
            // Doctrine kümmert sich um die Löschung abhängiger Daten 
            // (Voraussetzung: cascade={"remove"} oder orphanRemoval=true in Entity)
            $this->em->remove($exam);
            $this->em->flush();
            $this->addFlash('success', 'Prüfung gelöscht.');
        }

        return $this->redirectToRoute('app_exams_dashboard');
    }

    #[Route('/{id}/add_participant', name: 'add_participant', methods: ['GET', 'POST'])]
    public function addParticipant(int $id, Request $request, ExamRepository $examRepo, UserRepository $userRepo): Response
    {
        $exam = $examRepo->find($id);
        if (!$exam) throw $this->createNotFoundException();

        if ($request->isMethod('POST')) {
            $this->handleAddSingleParticipant($request, $exam, $userRepo);
            return $this->redirectToRoute('app_exams_add_participant', ['id' => $id, 'q' => $request->query->get('q')]);
        }

        // Liste aller verfügbaren User, die noch nicht in der Prüfung sind
        // Da die Logik ähnlich zu 'missingUsers' ist, könnten wir die Repo-Methode nutzen,
        // aber hier geht es um ALLE User, nicht nur die aus den Gruppen.
        // Das ist eine ähnliche Query, daher nutzen wir hier die gleiche Methode oder bauen eine allgemeinere.
        // Für dieses Beispiel nutze ich die existierende Missing-Logik:
        $searchTerm = trim($request->query->get('q', ''));
        $users = $examRepo->findMissingUsersForExam($exam, $searchTerm); 
        // HINWEIS: findMissingUsersForExam filtert aktuell nach Usern, die eine Gruppe HABEN, die der Exam zugeordnet ist.
        // Wenn du ALLE User willst, müsstest du den Gruppen-Join im Repository entfernen.

        $missingStudentsData = [];
        foreach ($users as $user) {
            $p = $user->getParticipant();
            $missingStudentsData[] = [
                'account' => $user->getAct(),
                'name'    => $user->getFirstname() . ' ' . $user->getLastname(),
                'dob'     => $p?->getGeburtsdatum()?->format('Y-m-d'),
                'gender'  => $p?->getGeschlecht() ?? 'MALE'
            ];
        }

        return $this->render('exams/add_participant.html.twig', [
            'exam' => ['id' => $exam->getId(), 'year' => $exam->getYear()],
            'missing_students' => $missingStudentsData,
            'search_term' => $searchTerm
        ]);
    }

    #[Route('/{id}/stats', name: 'stats', methods: ['GET'])]
    public function stats(int $id, ExamRepository $examRepo, ExamParticipantRepository $epRepo): Response
    {
        $exam = $examRepo->find($id);
        if (!$exam) throw $this->createNotFoundException();

        // 1. Medaillen-Statistik
        // Wir nutzen den QueryBuilder im Repository oder zählen iterativ (bei kleinen Mengen OK)
        $participants = $exam->getExamParticipants();
        $stats = ['Gold' => 0, 'Silber' => 0, 'Bronze' => 0, 'Ohne' => 0];

        foreach ($participants as $ep) {
            $pts = $ep->getTotalPoints();
            if ($pts >= 11) $stats['Gold']++;
            elseif ($pts >= 8) $stats['Silber']++;
            elseif ($pts >= 4) $stats['Bronze']++;
            else $stats['Ohne']++;
        }

        // 2. Top-Listen (Ergebnisse laden)
        // Hier laden wir die Ergebnisse effizient über das ParticipantRepo mit Joins
        $results = $epRepo->findResultsForStats($exam); // << Muss ins Repo!

        $topList = [];
        // Die Sortierlogik bleibt PHP-seitig, da sie komplex (Calculated vs Measured) ist.
        foreach ($results as $res) {
            $discName = $res->getDiscipline()->getName();
            $gender = $res->getExamParticipant()->getParticipant()->getGeschlecht();
            $genderKey = match($gender) { 'MALE' => 'Männlich', 'FEMALE' => 'Weiblich', default => 'Divers' };
            
            // Alter
            $age = $res->getExamParticipant()->getAge();
            $akKey = 'AK ' . $age;

            if (!isset($topList[$discName][$genderKey][$akKey])) {
                $topList[$discName][$genderKey][$akKey] = [];
            }

            $topList[$discName][$genderKey][$akKey][] = [
                'firstname' => $res->getExamParticipant()->getParticipant()->getUser()->getFirstname(),
                'lastname' => $res->getExamParticipant()->getParticipant()->getUser()->getLastname(),
                'points' => $res->getPoints(),
                'value' => $res->getLeistung(),
                'unit' => $res->getDiscipline()->getEinheit(),
                'type' => $res->getDiscipline()->getBerechnungsart(),
                'groups' => '...' // Gruppen zu laden ist teuer, evtl. weglassen oder vorladen
            ];
        }

        // Sortieren
        foreach ($topList as $d => &$gend) {
            foreach ($gend as $g => &$aks) {
                foreach ($aks as $ak => &$rows) {
                    usort($rows, function($a, $b) {
                        if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
                        // Tie-Breaker: Leistung
                        if ($a['type'] === 'BIGGER') return $b['value'] <=> $a['value'];
                        return $a['value'] <=> $b['value'];
                    });
                    $rows = array_slice($rows, 0, 10);
                }
            }
        }

        return $this->render('exams/stats.html.twig', [
            'exam' => ['id' => $exam->getId(), 'name' => $exam->getName(), 'year' => $exam->getYear()],
            'stats' => $stats,
            'topList' => $topList,
            'totalParticipants' => count($participants)
        ]);
    }

    // --- HELPER METHODS (Private Logic) ---

    /**
     * Importiert alle User einer Gruppe in die Prüfung
     */
    private function importParticipantsFromGroup(Exam $exam, Group $group): int
    {
        $count = 0;
        foreach ($group->getUsers() as $user) {
            if ($this->addParticipantToExam($exam, $user)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Handled den Request für einzelnen Teilnehmer hinzufügen
     */
    private function handleAddSingleParticipant(Request $request, Exam $exam, UserRepository $userRepo): void
    {
        $account = trim($request->request->get('account', ''));
        $gender = $request->request->get('gender');
        $dobStr = $request->request->get('dob');
        
        $user = $userRepo->findOneBy(['act' => $account]);

        if ($user) {
            // Pool Daten aktualisieren
            $participant = $this->getOrCreateParticipant($user);
            if ($dobStr) $participant->setGeburtsdatum(new \DateTime($dobStr));
            if ($gender) $participant->setGeschlecht($gender);
            
            // Zur Prüfung hinzufügen
            $this->addParticipantToExam($exam, $user, $participant); // Übergibt $participant um neu-query zu sparen
            
            $this->em->flush();
            $this->addFlash('success', 'Teilnehmer hinzugefügt/aktualisiert.');
        } else {
            $this->addFlash('error', 'Benutzerkonto nicht gefunden.');
        }
    }

    private function getOrCreateParticipant(User $user): Participant
    {
        if ($user->getParticipant()) {
            return $user->getParticipant();
        }

        $p = new Participant();
        $p->setUser($user);
        $p->setUsername($user->getAct()); // Fallback/Cache
        $this->em->persist($p);
        
        // Relation im User aktualisieren (für denselben Request wichtig)
        $user->setParticipant($p);
        
        return $p;
    }

    private function addParticipantToExam(Exam $exam, User $user, ?Participant $participant = null): bool
    {
        if (!$participant) {
            $participant = $this->getOrCreateParticipant($user);
        }

        // Prüfen ob schon drin (Collection Check)
        // Performance-Tipp: Bei sehr vielen Teilnehmern > 1000 lieber Query checken, 
        // aber für normale Klassen reicht der Collection Check
        foreach ($exam->getExamParticipants() as $ep) {
            if ($ep->getParticipant() === $participant) {
                return false; // Schon drin
            }
        }

        // Alter berechnen
        $age = 0;
        if ($participant->getGeburtsdatum()) {
            $birthYear = (int)$participant->getGeburtsdatum()->format('Y');
            $age = $exam->getYear() - $birthYear;
        }

        $ep = new ExamParticipant();
        $ep->setExam($exam);
        $ep->setParticipant($participant);
        $ep->setAge($age);
        
        $this->em->persist($ep);
        return true;
    }
}