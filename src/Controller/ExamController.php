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
use App\Repository\ParticipantRepository;
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
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }
        
        // KORREKTUR: Wir filtern hier nach 'examiner', nicht nach Institution.
        // So verschwinden Prüfungen, die man abgegeben hat, aus der Liste.
        $exams = $examRepo->findBy(
            ['examiner' => $user], 
            ['year' => 'DESC', 'date' => 'DESC']
        );

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

            foreach ($exam->getExamParticipants() as $ep) {
                $participant = $ep->getParticipant();
                if (!$participant || !$participant->getUser()) {
                    continue; 
                }

                $userId = $participant->getUser()->getId();
                
                if (!isset($yearlyStats[$year]['unique_users'][$userId])) {
                     $yearlyStats[$year]['stats']['Total']++;
                     $yearlyStats[$year]['unique_users'][$userId] = true;
                }

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
    public function new(Request $request, GroupRepository $groupRepo, ParticipantRepository $participantRepo): Response
    {
        $user = $this->getUser();
        $institution = $user ? $user->getInstitution() : null;

        if (!$institution) {
            $this->addFlash('error', 'Fehler: Deinem Benutzer ist keine Institution zugewiesen.');
            return $this->redirectToRoute('app_exams_dashboard');
        }

        $currentYear = (int)date('Y'); 

        if ($request->isMethod('POST')) {
            try {
                $name = trim($request->request->get('exam_name', ''));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;
                
                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;
                
                $groupIds = $request->request->all('groups') ?? []; 

                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                $exam->setCreator($user->getUserIdentifier());
                $exam->setInstitution($institution);
                
                // WICHTIG: Den Ersteller automatisch als Prüfer setzen
                $exam->setExaminer($user);
                
                $this->em->persist($exam);

                $usedGroupIds = $groupRepo->findGroupIdsUsedInYear($institution, $year);

                $countAdded = 0;
                foreach ($groupIds as $groupId) {
                    if (in_array($groupId, $usedGroupIds)) {
                        continue; 
                    }

                    $group = $groupRepo->findOneBy([
                        'id' => $groupId,
                        'institution' => $institution
                    ]);

                    if ($group) {
                        $exam->addGroup($group);
                        $countAdded += $this->importParticipantsFromGroup($exam, $group, $participantRepo);
                    }
                }

                $this->em->flush();

                $this->addFlash('success', "Prüfung angelegt. $countAdded Teilnehmer hinzugefügt.");
                return $this->redirectToRoute('app_exams_dashboard');

            } catch (\Throwable $e) {
                $this->addFlash('error', 'Fehler beim Anlegen: ' . $e->getMessage());
            }
        }

        $allGroups = $groupRepo->findBy(
            ['institution' => $institution], 
            ['name' => 'ASC']
        );

        $usedGroupIds = $groupRepo->findGroupIdsUsedInYear($institution, $currentYear);

        $groupsForDropdown = [];
        foreach ($allGroups as $g) {
            if (!in_array($g->getId(), $usedGroupIds)) {
                $groupsForDropdown[$g->getName()] = $g->getId();
            }
        }

        return $this->render('exams/new.html.twig', [
            'groups' => $groupsForDropdown,
            'default_year' => $currentYear
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id, 
        Request $request, 
        ExamRepository $examRepo, 
        GroupRepository $groupRepo, 
        UserRepository $userRepo,
        ParticipantRepository $participantRepo 
    ): Response
    {
        $exam = $examRepo->find($id);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden');

        $user = $this->getUser();
        $institution = $user ? $user->getInstitution() : null;

        // 1. CHECK: Gehört Prüfung zur Schule?
        if (!$institution || $exam->getInstitution() !== $institution) {
            throw $this->createAccessDeniedException('Falsche Institution.');
        }

        // 2. CHECK: Bin ich der Prüfer ODER Admin?
        $isExaminer = $exam->getExaminer() === $user;
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPER_ADMIN');

        if (!$isExaminer && !$isAdmin) {
             throw $this->createAccessDeniedException('Du bist nicht mehr der verantwortliche Prüfer für diese Prüfung.');
        }

        // --- POST HANDLING ---
        if ($request->isMethod('POST')) {
            
            // A. STAMMDATEN & ÜBERGABE
            if ($request->request->has('update_exam_data')) {
                $exam->setName($request->request->get('exam_name'));
                $exam->setYear((int)$request->request->get('exam_year'));
                
                $dateStr = $request->request->get('exam_date');
                if ($dateStr) {
                    $exam->setDate(new \DateTime($dateStr));
                }

                // --- LOGIK: PRÜFUNG ÜBERGEBEN ---
                $newExaminerId = $request->request->get('examiner_id');
                $transferHappened = false;

                if ($newExaminerId) {
                    $newExaminer = $userRepo->find($newExaminerId);
                    
                    // Prüfen: Existiert User? Gleiche Schule? Ist es wirklich jemand anderes?
                    if ($newExaminer && 
                        $newExaminer->getInstitution() === $institution && 
                        $newExaminer->getId() !== $user->getId()) {
                        
                        $exam->setExaminer($newExaminer);
                        $transferHappened = true;
                    }
                }

                $this->em->flush();

                // WENN ÜBERGEBEN: SOFORT ZUM DASHBOARD
                if ($transferHappened) {
                    $this->addFlash('success', 'Prüfung erfolgreich an ' . $newExaminer->getLastname() . ' übergeben.');
                    return $this->redirectToRoute('app_exams_dashboard');
                }

                $this->addFlash('success', 'Stammdaten gespeichert.');
                return $this->redirectToRoute('app_exams_edit', ['id' => $id]);
            }

            // B. GRUPPEN HINZUFÜGEN
            if ($request->request->has('add_groups')) {
                 $groupIds = $request->request->all('group_ids', []); 
                 
                 foreach ($groupIds as $gid) {
                    $grp = $groupRepo->find($gid);
                    if ($grp && $grp->getInstitution() === $institution) {
                        $exam->addGroup($grp);
                        $this->importParticipantsFromGroup($exam, $grp, $participantRepo);
                    }
                 }

                 $this->em->flush();
                 return $this->redirectToRoute('app_exams_edit', ['id' => $id]);
            }

            // C. GRUPPE ENTFERNEN
            if ($request->request->has('remove_group')) {
                 $groupId = $request->request->get('remove_group');
                 if (!empty($groupId)) {
                    $group = $groupRepo->find((int)$groupId);
                    if ($group) {
                        $exam->removeGroup($group);
                        $this->em->flush();
                        $this->addFlash('success', 'Gruppe entfernt.');
                    }
                 }
            }
        }

        // --- VIEW DATEN AUFBEREITEN ---
        
        $assignedGroups = $exam->getGroups();
        $allGroups = $groupRepo->findBy(['institution' => $institution], ['name' => 'ASC']);
        $usedGroupIdsInYear = $groupRepo->findGroupIdsUsedInYear($institution, $exam->getYear());

        $availableGroups = [];
        foreach ($allGroups as $g) {
            if (!in_array($g->getId(), $usedGroupIdsInYear) && !$assignedGroups->contains($g)) {
                $availableGroups[] = $g; 
            }
        }

        // --- KOLLEGEN FILTERN (EXAMINER / ADMIN / SUPER_ADMIN) ---
        $allInstitutionUsers = $userRepo->findBy(
            ['institution' => $institution], 
            ['lastname' => 'ASC']
        );

        $colleagues = [];
        foreach ($allInstitutionUsers as $u) {
            $roles = $u->getRoles();
            if (in_array('ROLE_EXAMINER', $roles) || 
                in_array('ROLE_ADMIN', $roles) || 
                in_array('ROLE_SUPER_ADMIN', $roles)) {
                
                $colleagues[] = $u;
            }
        }

        return $this->render('exams/edit.html.twig', [
            'exam' => $exam,
            'assigned_groups' => $assignedGroups,
            'available_groups' => $availableGroups,
            'colleagues' => $colleagues,
            'missing_students' => [],
        ]);
    }

    private function importParticipantsFromGroup(Exam $exam, Group $group, ParticipantRepository $participantRepo): int
    {
        $existingParticipantIds = [];
        foreach ($exam->getExamParticipants() as $participantParams) {
            if ($participantParams->getParticipant()) {
                $existingParticipantIds[] = $participantParams->getParticipant()->getId();
            }
        }

        $count = 0;
        foreach ($group->getUsers() as $user) {
            // OPTIMIERUNG: getOrCreate nutzen, damit auch Schüler ohne Profil angelegt werden
            $participant = $this->getOrCreateParticipant($user);

            if (!in_array($participant->getId(), $existingParticipantIds)) {
                $newParticipation = new ExamParticipant();
                $newParticipation->setExam($exam);
                $newParticipation->setParticipant($participant);
                
                if ($participant->getGeburtsdatum()) {
                    $birthYear = (int)$participant->getGeburtsdatum()->format('Y');
                    $age = $exam->getYear() - $birthYear;
                    $newParticipation->setAge($age);
                }

                $exam->addExamParticipant($newParticipation);
                $count++;
            }
        }
        
        return $count;
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

        $searchTerm = trim($request->query->get('q', ''));
        $users = $examRepo->findMissingUsersForExam($exam, $searchTerm); 

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
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden.');

        $participants = $exam->getExamParticipants();
        $stats = ['Gold' => 0, 'Silber' => 0, 'Bronze' => 0, 'Ohne' => 0];

        foreach ($participants as $ep) {
            $pts = $ep->getTotalPoints();
            if ($pts >= 11) $stats['Gold']++;
            elseif ($pts >= 8) $stats['Silber']++;
            elseif ($pts >= 4) $stats['Bronze']++;
            else $stats['Ohne']++;
        }

        $results = $epRepo->findResultsForStats($exam);
        $topList = [];

        foreach ($results as $res) {
            $examParticipant = $res->getExamParticipant();
            $participant = $examParticipant->getParticipant();
            $user = $participant->getUser();
            $discipline = $res->getDiscipline();

            $discName = $discipline->getName();
            
            $gender = $participant->getGeschlecht();
            $genderKey = match($gender) { 
                'MALE' => 'Männlich', 
                'FEMALE' => 'Weiblich', 
                default => 'Divers' 
            };
            
            $age = $examParticipant->getAge(); 
            $akKey = 'AK ' . $age;

            if (!isset($topList[$discName][$genderKey][$akKey])) {
                $topList[$discName][$genderKey][$akKey] = [];
            }

            $topList[$discName][$genderKey][$akKey][] = [
                'firstname' => $user->getFirstname(),
                'lastname'  => $user->getLastname(),
                'points'    => $res->getPoints(),
                'value'     => method_exists($res, 'getLeistung') ? $res->getLeistung() : $res->getValue(), 
                'unit'      => $discipline->getEinheit(),
                'type'      => $discipline->getBerechnungsart(),
                'group_name' => '...', 
            ];
        }

        foreach ($topList as $disc => &$genders) {
            foreach ($genders as $gender => &$aks) {
                foreach ($aks as $ak => &$rows) {
                    usort($rows, function($a, $b) {
                        if ($a['points'] !== $b['points']) {
                            return $b['points'] <=> $a['points'];
                        }
                        
                        if (isset($a['type']) && $a['type'] === 'BIGGER') {
                            return $b['value'] <=> $a['value'];
                        }
                        return $a['value'] <=> $b['value'];
                    });

                    $rows = array_slice($rows, 0, 10);
                }
            }
        }
        unset($genders, $aks, $rows);

        return $this->render('exams/stats.html.twig', [
            'exam' => $exam, 
            'stats' => $stats,
            'topList' => $topList,
            'totalParticipants' => count($participants),
            'is_exam' => true, 
        ]);
    }

    private function handleAddSingleParticipant(Request $request, Exam $exam, UserRepository $userRepo): void
    {
        $account = trim($request->request->get('account', ''));
        $gender = $request->request->get('gender');
        $dobStr = $request->request->get('dob');
        
        $user = $userRepo->findOneBy(['act' => $account]);

        if ($user) {
            $participant = $this->getOrCreateParticipant($user);
            
            if ($dobStr) $participant->setGeburtsdatum(new \DateTime($dobStr));
            if ($gender) $participant->setGeschlecht($gender);
            
            $this->addParticipantToExam($exam, $user, $participant); 
            
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
        $p->setUsername($user->getAct());
        $this->em->persist($p);
        
        $user->setParticipant($p);
        
        return $p;
    }

    private function addParticipantToExam(Exam $exam, User $user, ?Participant $participant = null): bool
    {
        if (!$participant) {
            $participant = $this->getOrCreateParticipant($user);
        }

        foreach ($exam->getExamParticipants() as $ep) {
            if ($ep->getParticipant() === $participant) {
                return false; 
            }
        }

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