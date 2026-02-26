<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Exam;
use App\Entity\User;
use App\Repository\ExamRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\Participant;
use App\Entity\ExamParticipant;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_ADMIN')] // Sicherheitshalber
final class AdminController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'activeTab' => 'dashboard',
        ]);
    }

    #[Route('/exams', name: 'exam_overview')]
    public function exams(ExamRepository $examRepo, UserRepository $userRepo): Response
    {
        // 1. Institution des aktuellen Users ermitteln
        /** @var User $user */
        $user = $this->getUser();
        $currentInstitution = $user->getInstitution();

        // 2. Alle Prüfungen holen (ggf. gefiltert nach Institution)
        $exams = $examRepo->findBy(['institution' => $currentInstitution], ['date' => 'DESC']);

        // 3. Prüfer für das Dropdown-Menü laden (inkl. dir als Super-Admin)
        $examiners = $userRepo->findAvailableExaminers($currentInstitution);

        // 4. Statistik-Daten pro Prüfung aufbereiten
        $examsData = [];
        foreach ($exams as $exam) {
            $stats = [
                'gold' => 0,
                'silver' => 0,
                'bronze' => 0,
                'unassigned' => 0,
                // Falls du Kategorien wie Ausdauer/Kraft trackst:
                'cat_ausdauer' => 0,
                'cat_kraft' => 0,
                'cat_schnelligkeit' => 0,
                'cat_koordination' => 0,
            ];

            foreach ($exam->getExamParticipants() as $ep) {
                // Medaillen zählen (Beispiel-Logik)
                $medal = strtolower((string)$ep->getFinalMedal());
                if (isset($stats[$medal])) {
                    $stats[$medal]++;
                }

                // Zuordnungscheck (unassigned)
                $participant = $ep->getParticipant();
                $pUser = $participant->getUser();
                
                $hasNoGroups = ($pUser === null || count($pUser->getGroups()) === 0);
                $hasNoLegacy = empty($participant->getGroupName());

                if ($hasNoGroups && $hasNoLegacy) {
                    $stats['unassigned']++;
                }
            }

            $examsData[] = [
                'exam' => $exam,
                'stats' => $stats
            ];
        }

        // 5. Alles ans Template übergeben
        return $this->render('admin/exam_overview.html.twig', [
            'examsData' => $examsData,
            'examiners' => $examiners,
            'activeTab' => 'exams',
        ]);
    }

    #[Route('/api/exam/{id}/set-examiner', name: 'api_set_examiner', methods: ['POST'])]
    public function apiSetExaminer(Exam $exam, Request $request, EntityManagerInterface $em, UserRepository $userRepo): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $examinerId = $data['examiner_id'] ?? null;

        if (!$examinerId) {
            // Prüfer entfernen
            $exam->setExaminer(null);
        } else {
            $examiner = $userRepo->find($examinerId);
            if (!$examiner) {
                return $this->json(['error' => 'Prüfer nicht gefunden'], 404);
            }
            $exam->setExaminer($examiner);
        }

        $em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/exam/{id}', name: 'exam_show')]
    public function show(Exam $exam, ExamRepository $examRepo): Response
    {
        // Wir holen uns die User, die theoretisch teilnehmen könnten (aus den Gruppen)
        $potentialParticipants = $examRepo->findMissingUsersForExam($exam);

        return $this->render('admin/exam_show.html.twig', [
            'exam' => $exam,
            'potentialParticipants' => $potentialParticipants,
            'activeTab' => 'exams',
        ]);
    }

    #[Route('/exam/{id}/add-user/{userId}', name: 'exam_add_user', methods: ['POST'])]
    public function addUserToExam(Exam $exam, int $userId, EntityManagerInterface $em): Response
    {
        $user = $em->getRepository(User::class)->find($userId);
        $participant = $em->getRepository(Participant::class)->findOneBy(['user' => $user]);

        if (!$user || !$participant) {
            $this->addFlash('danger', 'Nutzer oder Teilnehmer-Profil nicht gefunden.');
            return $this->redirectToRoute('admin_exam_show', ['id' => $exam->getId()]);
        }

        // --- PRÄZISE DATUMS-DIAGNOSE ---
        $examYear = $exam->getYear(); 
        $birthDate = $participant->getGeburtsdatum();

        if (!$examYear) {
            $this->addFlash('danger', 'FEHLER: Die Prüfung hat kein Jahr (exam_year) hinterlegt!');
            return $this->redirectToRoute('admin_exam_show', ['id' => $exam->getId()]);
        }

        if (!$birthDate) {
            $this->addFlash('danger', 'FEHLER: Der Teilnehmer hat kein Geburtsdatum!');
            return $this->redirectToRoute('admin_exam_show', ['id' => $exam->getId()]);
        }

        try {
            $exists = $em->getRepository(ExamParticipant::class)->findOneBy([
                'exam' => $exam,
                'participant' => $participant
            ]);

            if ($exists) {
                $this->addFlash('info', $user->getFirstname() . ' ist bereits registriert.');
                return $this->redirectToRoute('admin_exam_show', ['id' => $exam->getId()]);
            }

            $ep = new ExamParticipant();
            $ep->setExam($exam);
            $ep->setParticipant($participant);

            // Berechnung (da oben geprüft, hier sicher)
            $birthYear = (int)$birthDate->format('Y');
            $age = $examYear - $birthYear; 

            $ep = new ExamParticipant();
            $ep->setExam($exam);
            $ep->setParticipant($participant);

            // WICHTIG: Deine Entity nutzt $age für die Spalte age_year.
            // Stelle sicher, dass setAge auch wirklich den Wert setzt.
            $ep->setAge((int)$age); 

            // Diese Felder hast du in der Entity mit Default-Werten, 
            // aber wir setzen sie hier sicherheitshalber explizit:
            $ep->setTotalPoints(0);
            $ep->setFinalMedal('NONE');

            $em->persist($ep);
            $em->flush();
            $this->addFlash('success', sprintf('%s hinzugefügt. Alter für Prüfung: %d Jahre.', $user->getFirstname(), $age));

        } catch (\Exception $e) {
            $this->addFlash('danger', 'Datenbank-Fehler: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_exam_show', ['id' => $exam->getId()]);
    }

    #[Route('/exam/{id}/delete', name: 'exam_delete', methods: ['POST'])]
    public function delete(Exam $exam, EntityManagerInterface $em, Request $request): Response
    {
        // CSRF Check für Sicherheit
        if ($this->isCsrfTokenValid('delete' . $exam->getId(), $request->request->get('_token'))) {
            $em->remove($exam);
            $em->flush();
            $this->addFlash('success', 'Prüfung wurde gelöscht.');
        }

        return $this->redirectToRoute('admin_exam_overview');
    }

    #[Route('/exam/new', name: 'exam_new', methods: ['POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $institution = $user->getInstitution();

        // 1. Daten aus dem Request holen
        $name = $request->request->get('name');
        $year = (int)$request->request->get('year');
        $dateString = $request->request->get('date');

        // 2. Validierung (minimal)
        if (!$name || !$dateString) {
            $this->addFlash('danger', 'Name und Datum müssen ausgefüllt sein.');
            return $this->redirectToRoute('admin_exam_overview');
        }

        // 3. Entity erstellen und befüllen
        $exam = new Exam();
        $exam->setName($name);
        $exam->setYear($year);
        
        try {
            $exam->setDate(new \DateTime($dateString));
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Ungültiges Datumsformat.');
            return $this->redirectToRoute('admin_exam_overview');
        }

        // Automatisch die Institution setzen
        $exam->setInstitution($institution);
        
        // Falls deine Entity CreatedAt/UpdatedAt nutzt:
        if (method_exists($exam, 'setCreatedAt')) {
            $exam->setCreatedAt(new \DateTimeImmutable());
        }

        $em->persist($exam);
        $em->flush();

        $this->addFlash('success', sprintf('Prüfung "%s" wurde erfolgreich angelegt.', $name));

        return $this->redirectToRoute('admin_exam_overview');
    }

    #[Route('/fix-names', name: 'fix_names')]
        public function fixNames(EntityManagerInterface $em, UserRepository $userRepo): Response
        {
            $users = $userRepo->findAll();
            $count = 0;

            foreach ($users as $user) {
                $oldFirst = $user->getFirstname();
                $oldLast = $user->getLastname();

                $user->setFirstname($oldLast);
                $user->setLastname($oldFirst);
                
                $count++;
            }

            $em->flush();
            $this->addFlash('success', "$count Namen wurden erfolgreich getauscht.");
            return $this->redirectToRoute('admin_exam_overview');
        }
}