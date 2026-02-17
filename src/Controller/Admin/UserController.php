<?php

namespace App\Controller\Admin; // <--- NEUER NAMESPACE

use App\Entity\User;
use App\Form\UserAdminType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

// Wir behalten den Route-Namen 'admin_user_' bei, damit die Links im Template funktionieren
#[Route('/admin/user', name: 'admin_user_')]
class UserController extends AbstractController // <--- NEUER KLASSENNAME
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Standard: Wir filtern nach der Schule des aktuellen Admins
        $schoolFilter = $currentUser->getSchool();

        // AUSNAHME: Wenn ich Super-Admin bin, will ich vielleicht ALLE sehen?
        // Wenn du das willst, setze $schoolFilter auf null.
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            $schoolFilter = null; // Zeigt alle Schulen an
        }

        return $this->render('admin/user/index.html.twig', [
            // Wir übergeben die Schule an die Suchfunktion
            'users' => $userRepository->findStaff($schoolFilter),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {
        $user = new User();
        
        // Neue User im Admin-Bereich sind automatisch Prüfer
        $user->setRoles(['ROLE_EXAMINER']); 

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

            $this->addFlash('success', 'PrüferIn erfolgreich angelegt.');
            
            // Redirect Route bleibt gleich, da wir oben im Route-Attribut 'admin_user_' definiert haben
            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
            'form' => $form,
            'title' => 'PrüferIn anlegen'
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
            'title' => 'PrüferIn bearbeiten'
        ]);
    }
}