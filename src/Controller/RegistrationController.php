<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request, 
        UserPasswordHasherInterface $userPasswordHasher, 
        EntityManagerInterface $entityManager
    ): Response {
        // Wenn bereits eingeloggt, zum Dashboard schicken
        if ($this->getUser()) {
            return $this->redirectToRoute('app_exams_dashboard');
        }

        if ($request->isMethod('POST')) {
            $username = $request->request->get('username');
            $password = $request->request->get('password');
            $firstname = $request->request->get('firstname');
            $lastname = $request->request->get('lastname');

            // Validierung (Minimalbeispiel)
            if (!$username || !$password) {
                $this->addFlash('error', 'Bitte füllen Sie alle Pflichtfelder aus.');
                return $this->redirectToRoute('app_register');
            }

            $user = new User();
            $user->setUsername($username);
            $user->setFirstname($firstname);
            $user->setLastname($lastname);
            $user->setSource('csv'); // Kennzeichnung als manueller Account
            $user->setRoles(['ROLE_USER']); // Standard-Rolle

            // Passwort hashen
            $hashedPassword = $userPasswordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);

            try {
                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'Registrierung erfolgreich! Sie können sich jetzt anmelden.');
                return $this->redirectToRoute('app_login');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Benutzername bereits vergeben oder Datenbankfehler.');
            }
        }

        return $this->render('registration/register.html.twig');
    }
}