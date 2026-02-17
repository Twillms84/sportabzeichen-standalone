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

    #[Route('/settings', name: 'settings')]
    public function settings(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $institution = $user->getInstitution();

        if (!$institution) {
            throw $this->createNotFoundException('Keine Institution zugeordnet.');
        }

        // Hier nutzt du dein vorhandenes Formular für die Institution
        $form = $this->createForm(InstitutionType::class, $institution);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Die Schuldaten wurden erfolgreich aktualisiert.');
            return $this->redirectToRoute('admin_institution_settings');
        }

        return $this->render('admin/institution/settings.html.twig', [
            'institution' => $institution,
            'institutionForm' => $form->createView(),
            'activeTab' => 'institution_edit',
        ]);
    }
}