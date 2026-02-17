<?php

namespace App\Controller\Admin;

use App\Repository\InstitutionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/institutions', name: 'admin_institution_')]
#[IsGranted('ROLE_SUPER_ADMIN')] // Nur du darfst das!
class InstitutionController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(InstitutionRepository $institutionRepository): Response
    {
        return $this->render('admin/institution/index.html.twig', [
            'institutions' => $institutionRepository->findAllWithAdmins(),
        ]);
    }
}