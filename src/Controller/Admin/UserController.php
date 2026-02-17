<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserAdminType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/user', name: 'admin_user_')]
class UserController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Standard: Nur User der eigenen Institution
        $institutionFilter = $currentUser->getInstitution();

        // Super-Admin sieht alle Personen systemweit
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            $institutionFilter = null; 
        }

        return $this->render('admin/user/index.html.twig', [
            // WICHTIG: Hier müssen User-Objekte kommen, keine Institutionen!
            'users' => $userRepository->findStaff($institutionFilter),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request, 
        UserPasswordHasherInterface $userPasswordHasher, 
        EntityManagerInterface $entityManager,
        UserRepository $userRepository // <--- Repository injecten
    ): Response {
        $user = new User();
        // ... (Schul-Logik wie gehabt)

        $form = $this->createForm(UserAdminType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Manueller Check, falls die UniqueEntity-Validierung mal nicht greift
            $existingUser = $userRepository->findOneBy(['email' => $user->getEmail()]);
            if ($existingUser) {
                $this->addFlash('danger', 'Ein Konto mit dieser E-Mail existiert bereits im System.');
                return $this->render('admin/user/form.html.twig', [
                    'user' => $user,
                    'form' => $form,
                    'title' => 'PrüferIn / Admin anlegen'
                ]);
            }

            // Passwort hashen und speichern...
            $user->setPassword(
                $userPasswordHasher->hashPassword($user, $form->get('plainPassword')->getData())
            );

            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Personal erfolgreich angelegt.');
            return $this->redirectToRoute('admin_user_index');
        }
        
        // ... restliches Render
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(UserAdminType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $user->setPassword(
                    $userPasswordHasher->hashPassword($user, $plainPassword)
                );
            }

            $entityManager->flush();

            $this->addFlash('success', 'Daten gespeichert.');
            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
            'form' => $form,
            'title' => 'Personal bearbeiten'
        ]);
    }
}