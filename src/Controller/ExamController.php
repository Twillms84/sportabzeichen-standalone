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
        $user = $this->getUser();
        $institution = $user ? $user->getInstitution() : null;

        if (!$institution) {
            $this->addFlash('error', 'Fehler: Deinem Benutzer ist keine Institution zugewiesen.');
            return $this->redirectToRoute('app_exams_dashboard');
        }

        // Standardjahr für die Erstansicht (meist aktuelles Jahr)
        $currentYear = (int)date('Y'); 

        if ($request->isMethod('POST')) {
            try {
                $name = trim($request->request->get('exam_name', ''));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;
                
                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;
                
                // FIX: Sicherer Zugriff auf das Array. 
                // Falls 'groups' im Formular name="groups[]" heißt, liefert ->all('groups') das Array korrekt.
                $groupIds = $request->request->all('groups') ?? []; 

                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                $exam->setCreator($user->getUserIdentifier());
                $exam->setInstitution($institution);
                
                $this->em->persist($exam);

                // Validierung: Welche Gruppen sind in DIESEM Jahr ($year) schon vergeben?
                $usedGroupIds = $groupRepo->findGroupIdsUsedInYear($institution, $year);

                $countAdded = 0;
                foreach ($groupIds as $groupId) {
                    // Check: Ist Gruppe schon vergeben?
                    if (in_array($groupId, $usedGroupIds)) {
                        // Optional: Warnung loggen oder Flash-Message erweitern
                        continue; 
                    }

                    $group = $groupRepo->findOneBy([
                        'id' => $groupId,
                        'institution' => $institution
                    ]);

                    if ($group) {
                        $exam->addGroup($group);
                        // Teilnehmer kopieren
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

        // --- View Daten vorbereiten (mit Filterung) ---
        
        // 1. Alle Gruppen der Schule laden
        $allGroups = $groupRepo->findBy(
            ['institution' => $institution], 
            ['name' => 'ASC']
        );

        // 2. Bereits vergebene Gruppen für das Standardjahr holen
        // (Hinweis: Wenn der User das Jahr im Formular ändert, aktualisiert sich das Dropdown erst nach Reload. 
        // Für eine Live-Anpassung bräuchte man JavaScript/AJAX. Das hier deckt den 90% Fall ab.)
        $usedGroupIds = $groupRepo->findGroupIdsUsedInYear($institution, $currentYear);

        $groupsForDropdown = [];
        foreach ($allGroups as $g) {
            // Nur hinzufügen, wenn ID nicht in der Blacklist ist
            if (!in_array($g->getId(), $usedGroupIds)) {
                $groupsForDropdown[$g->getName()] = $g->getId();
            }
        }

        return $this->render('exams/new.html.twig', [
            'groups' => $groupsForDropdown,
            'default_year' => $currentYear
        ]);
    }

#[Route('/{id}/edit', name: 'app_exams_edit', methods: ['GET', 'POST'])]
public function edit(int $id, Request $request, ExamRepository $examRepo, GroupRepository $groupRepo, UserRepository $userRepo): Response
{
    $exam = $examRepo->find($id);
    if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden');

    $user = $this->getUser();
    $institution = $user ? $user->getInstitution() : null;

    // Sicherheitscheck: Gehört die Prüfung zur Institution des Users?
    if (!$institution || $exam->getInstitution() !== $institution) {
        throw $this->createAccessDeniedException('Du darfst diese Prüfung nicht bearbeiten.');
    }

    // --- POST HANDLING ---
    if ($request->isMethod('POST')) {
        
        // 1. STAMMDATEN SPEICHERN (Hier habe ich das hidden field 'update_exam_data' genutzt, das wir vorhin eingebaut haben)
        if ($request->request->has('update_exam_data')) {
             $exam->setName($request->request->get('exam_name'));
             $exam->setYear((int)$request->request->get('exam_year'));
             
             // Datum verarbeiten
             $dateStr = $request->request->get('exam_date');
             if ($dateStr) {
                 $exam->setDate(new \DateTime($dateStr));
             }

             $this->em->flush();
             $this->addFlash('success', 'Stammdaten gespeichert.');
             
             return $this->redirectToRoute('app_exams_edit', ['id' => $id]);
        }

        // 2. GRUPPEN HINZUFÜGEN
        if ($request->request->has('add_groups')) {
            
            // Eingaben normalisieren (Array oder einzelne ID)
            $groupIds = $request->request->all('group_ids', []); // Achtung: Name im Twig war group_ids[]

            $addedCountTotal = 0;
            
            // Welche Gruppen sind in diesem Jahr generell schon vergeben?
            $usedGroupIds = $groupRepo->findGroupIdsUsedInYear($institution, $exam->getYear());

            foreach ($groupIds as $gId) {
                $gId = (int)$gId;

                // Check 1: Ist die Gruppe schon in DIESER Prüfung? (Verhindert Duplikate)
                // Wir prüfen das direkt über die Collection, brauchen keine Hilfsmethode
                $groupAlreadyInExam = false;
                foreach ($exam->getGroups() as $existingGroup) {
                    if ($existingGroup->getId() === $gId) {
                        $groupAlreadyInExam = true; 
                        break;
                    }
                }

                if ($groupAlreadyInExam) {
                    continue; // Nächste Gruppe
                }

                // Check 2: Ist die Gruppe schon in einer ANDEREN Prüfung dieses Jahres?
                if (in_array($gId, $usedGroupIds)) {
                    $this->addFlash('warning', "Gruppe ID $gId ist bereits in einer anderen Prüfung vergeben.");
                    continue;
                }

                // Gruppe laden und hinzufügen
                $group = $groupRepo->findOneBy(['id' => $gId, 'institution' => $institution]);
                
                if ($group) {
                    $exam->addGroup($group);
                    
                    // WICHTIG: Hier Teilnehmer importieren. 
                    // Da ich deine Methode nicht kenne, hier der Aufruf deiner privaten Methode (siehe unten)
                    $addedCountTotal += $this->importParticipantsFromGroup($exam, $group);
                }
            }

            $this->em->flush();
            
            if ($addedCountTotal > 0) {
                 $this->addFlash('success', "$addedCountTotal Teilnehmer wurden importiert.");
            } else {
                 $this->addFlash('info', "Gruppen wurden zugeordnet.");
            }
            
            return $this->redirectToRoute('app_exams_edit', ['id' => $id]);
        }

        // 3. GRUPPE ENTFERNEN
        if ($request->request->has('remove_group')) {
            // Im Twig sendest du value="{{ grp.act }}"? Besser wäre die ID. 
            // Falls du ID sendest (empfohlen):
            $groupId = $request->request->get('remove_group');
            
            // Falls du 'act' sendest, musst du findOneBy(['act' => ...]) machen.
            // Ich gehe hier mal von ID aus, da das sicherer ist:
            $group = $groupRepo->find($groupId); // Oder findOneBy(['act' => ...])
            
            if ($group && $group->getInstitution() === $institution) {
                $exam->removeGroup($group);
                
                // OPTIONAL: Hier müsstest du ggf. auch die Teilnehmer entfernen, 
                // die zu dieser Gruppe gehören, sonst hast du "Leichen" in der Prüfung.
                // $this->removeParticipantsByGroup($exam, $group);

                $this->em->flush();
                $this->addFlash('success', 'Gruppe entfernt.');
            }
            return $this->redirectToRoute('app_exams_edit', ['id' => $id]);
        }
    }

    // --- VIEW DATEN ---
    
    // 1. Bereits zugewiesene Gruppen
    $assignedGroups = $exam->getGroups();
    
    // 2. Alle Gruppen der Schule laden
    $allGroups = $groupRepo->findBy(['institution' => $institution], ['name' => 'ASC']);

    // 3. IDs von Gruppen, die IRGENDWO in diesem Jahr benutzt werden
    $usedGroupIdsInYear = $groupRepo->findGroupIdsUsedInYear($institution, $exam->getYear());

    // 4. Verfügbare Gruppen berechnen
    $availableGroups = [];
    foreach ($allGroups as $g) {
        $gId = $g->getId();

        // Ist die Gruppe schon in DIESER Prüfung?
        $isInThisExam = $assignedGroups->contains($g);

        // Ist die Gruppe in einer ANDEREN Prüfung?
        // (usedGroupIdsInYear enthält AUCH die Gruppen dieser Prüfung, 
        // deshalb reicht "in_array", um sie rauszufiltern)
        $isUsed = in_array($gId, $usedGroupIdsInYear);

        // Wir zeigen sie nur an, wenn sie NICHT benutzt wird (weder hier noch woanders)
        // ODER: Du möchtest Gruppen anzeigen, die noch gar nicht benutzt werden.
        if (!$isUsed && !$isInThisExam) {
             $availableGroups[$gId] = $g->getName(); // Key = ID, Value = Name für das Twig Array
        }
    }

    return $this->render('exams/edit.html.twig', [
        'exam' => $exam, // Du kannst das Entity direkt übergeben, Twig kann $exam.name aufrufen
        'assigned_groups' => $assignedGroups,
        'available_groups' => $availableGroups,
        'missing_students' => [], // Platzhalter
    ]);
}

    // Helper um schnell zu prüfen, ob Gruppe schon im Exam Objekt ist (ohne DB Query)
    private function isGroupInExam(Exam $exam, int $groupId): bool 
    {
        foreach ($exam->getGroups() as $g) {
            if ($g->getId() === $groupId) return true;
        }
        return false;
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
            if ($dobStr) $participant->setBirthdate(new \DateTime($dobStr));
            if ($gender) $participant->setGender($gender);
            
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

    /**
     * Findet IDs von Gruppen, die in einem bestimmten Jahr bereits einer Prüfung zugewiesen sind.
     * * @return int[]
     */
    public function findGroupIdsUsedInYear(Institution $institution, int $year): array
    {
        // Wir holen nur die IDs, das ist performanter
        $result = $this->createQueryBuilder('g')
            ->select('g.id')
            ->join('g.exams', 'e')
            ->where('e.institution = :institution')
            ->andWhere('e.year = :year')
            ->setParameter('institution', $institution)
            ->setParameter('year', $year)
            ->getQuery()
            ->getScalarResult();

        // Doctrine gibt hier oft [['id' => 1], ['id' => 2]] zurück, wir wollen [1, 2]
        return array_column($result, 'id');
    }
}