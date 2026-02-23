<?php

namespace App\Controller;

use App\Entity\Institution;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request, 
        UserPasswordHasherInterface $userPasswordHasher, 
        EntityManagerInterface $entityManager,
        VerifyEmailHelperInterface $verifyEmailHelper, 
        MailerInterface $mailer 
    ): Response {
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
            
            $institution->setRegistrarEmail($user->getEmail());

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

            // ZUERST in die Datenbank schreiben, damit der User eine ID bekommt!
            $entityManager->persist($user);
            $entityManager->flush();

            // --- 4. E-Mail mit Bestätigungslink senden ---
            
            // Signatur generieren
            $signatureComponents = $verifyEmailHelper->generateSignature(
                'app_verify_email',
                (string) $user->getId(),
                $user->getEmail(),
                ['id' => $user->getId()] // <-- DIESE ZEILE HAT BEI DIR GEFEHLT!
            );

            // E-Mail zusammenbauen
            $email = (new TemplatedEmail())
                ->from(new Address('info@heimserver24.de', 'OSA Cockpit'))
                ->to($user->getEmail())
                ->subject('Bitte bestätige deine E-Mail-Adresse')
                ->htmlTemplate('registration/confirmation_email.html.twig')
                ->context([
                    'signedUrl' => $signatureComponents->getSignedUrl(),
                    'expiresAtMessageKey' => $signatureComponents->getExpirationMessageKey(),
                    'expiresAtMessageData' => $signatureComponents->getExpirationMessageData(),
                ]);

            // E-Mail abfeuern
            $mailer->send($email);

            // Erfolgsmeldung für die Registrierung
            $this->addFlash('success', 'Registrierung erfolgreich! Wir haben dir eine E-Mail gesendet. Bitte klicke auf den Link darin, um deinen Account zu aktivieren.');
            
            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, UserRepository $userRepository, VerifyEmailHelperInterface $verifyEmailHelper, EntityManagerInterface $entityManager): Response
    {
                
        // Holt die User-ID aus dem Link in der E-Mail
        $id = $request->query->get('id');

        // NEU: Mit klarer Fehlermeldung statt heimlicher Weiterleitung
        if (null === $id) {
            $this->addFlash('danger', 'Fehler: Im Bestätigungslink fehlt die Benutzer-ID.');
            return $this->redirectToRoute('app_login');
        }

        $user = $userRepository->find($id);

        // NEU: Mit klarer Fehlermeldung
        if (null === $user) {
            $this->addFlash('danger', 'Fehler: Der Benutzer zu diesem Link existiert nicht mehr.');
            return $this->redirectToRoute('app_login');
        }

        // Validiere, ob der Link gültig ist
        try {
            $verifyEmailHelper->validateEmailConfirmation($request->getUri(), (string) $user->getId(), $user->getEmail());
        } catch (VerifyEmailExceptionInterface $e) {
            $this->addFlash('danger', 'Der Link ist ungültig oder abgelaufen. Bitte versuche es erneut.');
            return $this->redirectToRoute('app_login');
        }

        // --- Alles korrekt! User verifizieren ---
        $user->setIsVerified(true); 
        
        $entityManager->flush();

        $this->addFlash('success', 'Klasse! Deine E-Mail-Adresse wurde erfolgreich bestätigt. Du kannst dich jetzt anmelden.');

        return $this->redirectToRoute('app_login');
    }
}