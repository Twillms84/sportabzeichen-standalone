<?php

namespace App\Controller\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TokenLoginController extends AbstractController
{
    #[Route('/login/token/{token}', name: 'app_login_by_token')]
    public function loginByToken(
        string $token, 
        EntityManagerInterface $em, 
        Security $security
    ): Response {
        // 1. User anhand des Tokens suchen
        $user = $em->getRepository(User::class)->findOneBy(['loginToken' => $token]);

        if (!$user) {
            $this->addFlash('danger', 'Ungültiger QR-Code. Bitte wende dich an die Prüfer.');
            return $this->redirectToRoute('app_login');
        }

        // 2. User in der 'main' Firewall einloggen
        // Wir nutzen 'form_login' als authenticator, da dies in deiner yaml definiert ist
        $security->login($user, 'form_login', 'main');

        // 3. Weiterleitung zum persönlichen Bereich
        return $this->redirectToRoute('my_results'); 
    }
}