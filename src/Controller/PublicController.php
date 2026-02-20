<?php

namespace App\Controller;

use App\Repository\ExamRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicController extends AbstractController
{
    #[Route('/', name: 'app_home', priority: -10)] // <--- Priority hinzugefügt
    public function index(ExamRepository $examRepo): Response
    {
        return $this->render('public/landingpage.html.twig', [
            // Optional: Zeige stolze Zahlen (Total abgelegte Abzeichen etc.)
            'totalExams' => $examRepo->count([]),
        ]);
    }
}