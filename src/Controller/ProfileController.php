<?php

namespace App\Controller;

use App\Form\UserProfileType;
use App\Form\UserSettingsType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile', name: 'profile_')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher): Response
    {
        $user = $this->getUser();
        
        // Prüfen, ob der User Prüfer ist (Rollen-Check)
        $isExaminer = $this->isGranted('ROLE_EXAMINER');

        // WICHTIG: Option an das Formular übergeben
        $form = $this->createForm(UserProfileType::class, $user, [
            'is_examiner' => $isExaminer
        ]);
        
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            
            // Passwort-Logik
            if ($plainPassword) {
                // Falls das Feld currentPassword existiert (nur bei Prüfern der Fall laut FormType)
                if ($form->has('currentPassword')) {
                    $currentPassword = $form->get('currentPassword')->getData();
                    
                    if (!$currentPassword) {
                        $form->get('currentPassword')->addError(new FormError('Bitte geben Sie zur Sicherheit Ihr aktuelles Passwort ein.'));
                    } elseif (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                        $form->get('currentPassword')->addError(new FormError('Das aktuelle Passwort ist falsch.'));
                    } else {
                        $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
                    }
                } else {
                    // Für normale User ohne currentPassword-Zwang (z.B. Login via QR-Code Erst-Einrichtung)
                    $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
                }
            }

            if ($form->getErrors(true)->count() === 0) {
                $em->flush();
                $this->addFlash('success', 'Profildaten aktualisiert.');
                return $this->redirectToRoute('profile_index');
            }
        }

        return $this->render('profile/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}