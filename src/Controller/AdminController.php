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
        // 1. Hol den aktuell angemeldeten User
        /** @var User $user */
        $user = $this->getUser();
        
        // 2. Institution ermitteln (falls der User einer zugeordnet ist)
        // Ich gehe davon aus, dass dein User ein Feld 'getInstitution()' hat
        $currentInstitution = $user->getInstitution();

        // 3. Jetzt die Methode aufrufen (die wir im Repository erstellt haben)
        $examiners = $userRepo->findAvailableExaminers($currentInstitution);

        // ... Rest deines Codes (Stats berechnen, $examsData bauen etc.) ...

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
}