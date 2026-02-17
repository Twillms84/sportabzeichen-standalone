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
        $form = $this->createForm(UserProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            // Passwort Änderung Logik
            $plainPassword = $form->get('plainPassword')->getData();
            $currentPassword = $form->get('currentPassword')->getData();

            // Wenn ein neues Passwort eingegeben wurde...
            if ($plainPassword) {
                // ... muss auch das alte eingegeben sein
                if (!$currentPassword) {
                    $form->get('currentPassword')->addError(new FormError('Bitte geben Sie zur Sicherheit Ihr aktuelles Passwort ein.'));
                } 
                // ... und das alte muss korrekt sein
                elseif (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $form->get('currentPassword')->addError(new FormError('Das aktuelle Passwort ist falsch.'));
                } 
                // Alles okay -> Speichern
                else {
                    $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                    $user->setPassword($hashedPassword);
                }
            }

            // Nur speichern, wenn keine Fehler nachträglich hinzugefügt wurden
            if ($form->isValid()) {
                $em->flush();
                $this->addFlash('success', 'Profildaten aktualisiert.');
                return $this->redirectToRoute('profile_index');
            }
        }

        return $this->render('profile/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/settings', name: 'settings')]
    public function settings(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $form = $this->createForm(UserSettingsType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            // Hier kein Flash nötig, der visuelle Change reicht oft, aber kann man machen
            return $this->redirectToRoute('profile_settings');
        }

        return $this->render('profile/settings.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}