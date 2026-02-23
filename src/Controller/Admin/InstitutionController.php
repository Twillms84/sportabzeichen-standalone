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
        public function delete(Request $request, Institution $institution, EntityManagerInterface $entityManager): Response
        {
            if ($this->isCsrfTokenValid('delete'.$institution->getId(), $request->request->get('_token'))) {
                
                // ACHTUNG: Das hier löscht die Schule UND alle zugehörigen User (Kaskade)!
                $entityManager->remove($institution);
                $entityManager->flush();
                
                $this->addFlash('success', 'Schule und alle zugehörigen Benutzer wurden gelöscht.');
            }

            return $this->redirectToRoute('admin_institution_index', [], Response::HTTP_SEE_OTHER);
        }

        #[Route('/institution/{id}/update-license', name: 'admin_institution_update_license', methods: ['POST'])]
        public function updateLicense(Request $request, Institution $institution, EntityManagerInterface $em): Response
        {
            // CSRF-Schutz prüfen
            if (!$this->isCsrfTokenValid('update_license' . $institution->getId(), $request->request->get('_token'))) {
                $this->addFlash('error', 'Ungültiger Token.');
                return $this->redirectToRoute('admin_institutions_index'); // <-- Passe diese Route an deinen echten Index-Routennamen an!
            }

            $tier = $request->request->get('license_tier');
            $duration = (int) $request->request->get('license_duration'); // 3 oder 12

            $validTiers = ['free', 'basic', 'pro', 'max'];

            if (in_array($tier, $validTiers)) {
                $institution->setLicense($tier);

                if ($tier === 'free') {
                    // Free-Lizenz läuft nie ab
                    $institution->setLicenseValidUntil(null);
                } else {
                    // Ablaufdatum berechnen: Heute + X Monate
                    $expirationDate = new \DateTime();
                    $expirationDate->modify("+$duration months");
                    $institution->setLicenseValidUntil($expirationDate);
                }

                $em->flush();
                $this->addFlash('success', sprintf('Die Lizenz für "%s" wurde erfolgreich auf %s aktualisiert.', $institution->getName(), strtoupper($tier)));
            } else {
                $this->addFlash('error', 'Ungültiges Lizenzmodell ausgewählt.');
            }

            return $this->redirectToRoute('admin_institutions_index'); // <-- Passe diese Route an deinen echten Index-Routennamen an!
        }

    }   