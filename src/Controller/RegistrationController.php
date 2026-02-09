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
            
            // Institution speichern, um ID zu bekommen (passiert durch cascade persist oder beim flush)
            $entityManager->persist($institution);

            // 2. User vorbereiten
            // Passwort hashen
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );
            
            // Rolle Admin geben, da er die Institution erstellt hat
            $user->setRoles(['ROLE_ADMIN']);
            $user->setSource('register'); // Markierung, dass er sich selbst registriert hat
            
            // Namen aus "Verantwortlicher" parsen (optional, quick & dirty)
            $parts = explode(' ', $institution->getContactPerson(), 2);
            $user->setFirstname($parts[0] ?? 'Admin');
            $user->setLastname($parts[1] ?? 'User');

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