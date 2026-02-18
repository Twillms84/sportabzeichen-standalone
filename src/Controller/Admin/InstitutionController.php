<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\InstitutionType;
use App\Repository\InstitutionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\Institution;

#[Route('/admin/institutions', name: 'admin_institution_')]
// IsGranted von hier oben entfernen, damit nicht die ganze Klasse gesperrt ist!
class InstitutionController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')] // Nur der Super-Admin sieht die Liste aller Schulen
    public function index(InstitutionRepository $institutionRepository): Response
    {
        return $this->render('admin/institution/index.html.twig', [
            'institutions' => $institutionRepository->findAllWithAdmins(),
        ]);
    }

    #[Route('/settings', name: 'settings')]
    #[IsGranted('ROLE_ADMIN')] // Registrar (ROLE_ADMIN) und du (ROLE_SUPER_ADMIN) dürfen das
    public function settings(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $institution = $user->getInstitution();

        if (!$institution) {
            $this->addFlash('danger', 'Ihnen ist keine Institution zugeordnet.');
            return $this->redirectToRoute('admin_dashboard');
        }

        $form = $this->createForm(InstitutionType::class, $institution);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Die Schuldaten wurden erfolgreich aktualisiert.');
            
            // WICHTIG: Der Name der Route ist admin_institution_settings (wegen Präfix + name)
            return $this->redirectToRoute('admin_institution_settings');
        }

        // Achte darauf, dass die Datei wirklich edit.html.twig heißt (oder settings.html.twig)
        return $this->render('admin/institution/edit.html.twig', [
            'institution' => $institution,
            'institutionForm' => $form->createView(),
            'activeTab' => 'institution_edit',
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(Request $request, Institution $institution, EntityManagerInterface $em): Response
    {
        // CSRF-Schutz zur Sicherheit (verhindert ungewollte Klicks von extern)
        if ($this->isCSRFTokenValid('delete' . $institution->getId(), $request->request->get('_token'))) {
            
            // Optional: Prüfen, ob die Schule noch User hat
            if ($institution->getUsers()->count() > 0) {
                $this->addFlash('danger', 'Schule kann nicht gelöscht werden, da noch Benutzer zugeordnet sind.');
                return $this->redirectToRoute('admin_institution_index');
            }

            $em->remove($institution);
            $em->flush();

            $this->addFlash('success', 'Die Institution wurde vollständig entfernt.');
        }

        return $this->redirectToRoute('admin_institution_index');
    }
}