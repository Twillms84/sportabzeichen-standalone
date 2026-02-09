<?php

namespace App\Controller;

use App\Entity\Institution;
use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            // 1. Institution anlegen
            $institution = new Institution();
            $institution->setName($form->get('instName')->getData());
            $institution->setType($form->get('instType')->getData());
            $institution->setContactPerson($form->get('contactPerson')->getData());
            $institution->setZip($form->get('instZip')->getData());
            $institution->setCity($form->get('instCity')->getData());
            $institution->setStreet($form->get('instStreet')->getData());
            
            // --- WICHTIG: Hier fehlte die Zuweisung! ---
            // Die Schule gehört der E-Mail-Adresse, die sich gerade registriert.
            $institution->setRegistrarEmail($user->getEmail());
            // --------------------------------------------

            // Institution speichern
            $entityManager->persist($institution);

            // 2. User vorbereiten
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );
            
            $user->setRoles(['ROLE_ADMIN']);
            $user->setSource('register'); 
            
            // Namen aus "Verantwortlicher" parsen (Fallback Logik)
            $contactPerson = $institution->getContactPerson();
            if ($contactPerson) {
                $parts = explode(' ', $contactPerson, 2);
                $user->setFirstname($parts[0] ?? 'Admin');
                $user->setLastname($parts[1] ?? 'User');
            } else {
                $user->setFirstname('Admin');
                $user->setLastname('User');
            }

            // 3. User der Institution zuweisen
            $user->setInstitution($institution);

            $entityManager->persist($user);
            $entityManager->flush();

            // Weiterleitung zum Login
            $this->addFlash('success', 'Registrierung erfolgreich! Bitte logge dich ein.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }
}