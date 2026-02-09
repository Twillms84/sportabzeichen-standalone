<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class SetupController extends AbstractController
{
    #[Route('/setup-admin', name: 'app_setup_admin')]
    public function index(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        // Prüfen, ob der User schon da ist
        $exists = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        if ($exists) return new Response('Admin existiert bereits.');

        $user = new User();
        $user->setUsername('admin');
        $user->setFirstname('Root');
        $user->setLastname('Admin');
        $user->setSource('csv');
        $user->setRoles(['ROLE_ADMIN']);
        
        // Passwort festlegen (hier im Beispiel 'admin123')
        $user->setPassword($hasher->hashPassword($user, 'admin123'));

        $em->persist($user);
        $em->flush();

        return new Response('Admin-User "admin" mit Passwort "admin123" wurde erstellt!');
    }
}