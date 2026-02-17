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

        // KORRIGIERT: getInstitution() statt getSchool()
        $institutionFilter = $currentUser->getInstitution();

        // Wenn Super-Admin: Filter entfernen (alles sehen)
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            $institutionFilter = null; 
        }

        return $this->render('admin/user/index.html.twig', [
            'users' => $userRepository->findStaff($institutionFilter),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {
        $user = new User();
        
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // KORRIGIERT: Automatisch die Institution des Erstellers zuweisen
        if ($currentUser->getInstitution()) {
            $user->setInstitution($currentUser->getInstitution());
        }

        $form = $this->createForm(UserAdminType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Personal erfolgreich angelegt.');
            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
            'form' => $form,
            'title' => 'PrüferIn / Admin anlegen'
        ]);
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