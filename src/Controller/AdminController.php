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
        $exams = $examRepo->findAllWithStats();
        $examiners = $userRepo->findByRole('ROLE_EXAMINER'); // Annahme: Du hast so eine Methode oder nutzt findBy(['role' => ...])

        // Daten aufbereiten (Statistiken berechnen)
        $examData = [];
        foreach ($exams as $exam) {
            $stats = [
                'gold' => 0, 'silver' => 0, 'bronze' => 0,
                'cat_ausdauer' => 0, 'cat_kraft' => 0, 'cat_schnelligkeit' => 0, 'cat_koordination' => 0,
                'unassigned' => 0
            ];

            // Annahme: $exam->getExamParticipants() liefert die Teilnehmer
            foreach ($exam->getExamParticipants() as $ep) {
                // 1. Medaillen zählen (Annahme: Methode getMedal() existiert oder Feld 'medal')
                $medal = strtolower($ep->getFinalMedal() ?? ''); // z.B. 'gold', 'silver', 'bronze'
                if (isset($stats[$medal])) {
                    $stats[$medal]++;
                }

                if (!$ep->getParticipant()->hasAssignment()) {
                        $stats['unassigned']++;
                    }

                // 3. Kategorien zählen
                // Hier müsstest du prüfen, ob der Teilnehmer die Kategorien erfüllt hat.
                // Das ist Pseudo-Code, da ich deine Result-Struktur nicht exakt kenne.
                // if ($ep->hasCategoryFinished('ausdauer')) $stats['cat_ausdauer']++;
            }

            $examData[] = [
                'exam' => $exam,
                'stats' => $stats
            ];
        }

        return $this->render('admin/exam_overview.html.twig', [
            'activeTab' => 'exams',
            'examsData' => $examData, // Das Array mit Stats
            'examiners' => $examiners,
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