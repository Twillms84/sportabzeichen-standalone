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

        // 1. Standard: Wir filtern nach der Schule des aktuellen Admins
        $schoolFilter = $currentUser->getSchool();

        // 2. AUSNAHME: Super-Admin sieht ALLES (Filter auf null setzen)
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            $schoolFilter = null; 
        }

        return $this->render('admin/user/index.html.twig', [
            // Wir übergeben die Schule an die Suchfunktion im Repository
            'users' => $userRepository->findStaff($schoolFilter),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {
        $user = new User();
        
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // WICHTIG: Automatisch die Schule des Erstellers zuweisen!
        // (Damit der neue Prüfer nicht "heimatlos" ist)
        if ($currentUser->getSchool()) {
            $user->setSchool($currentUser->getSchool());
        }

        // HINWEIS: Wir setzen hier KEINE Rollen mehr manuell ($user->setRoles...).
        // Das übernimmt jetzt das Dropdown im Formular (UserAdminType).

        $form = $this->createForm(UserAdminType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Passwort hashen
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
        // Sicherheitscheck: Darf der aktuelle Admin diesen User überhaupt bearbeiten?
        // (Optional: Hier könnte man prüfen, ob $user->getSchool() == $currentUser->getSchool())

        $form = $this->createForm(UserAdminType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Passwort nur ändern, wenn etwas eingegeben wurde
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