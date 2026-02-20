<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route; // Wichtig: Attribute

class PublicController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Falls du willst, dass eingeloggte User die Landingpage NIE sehen:
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_exam_overview'); // Dein Dashboard-Name
        }

        // Ansonsten: Zeig die Landingpage
        return $this->render('public/landingpage.html.twig');
    }
}