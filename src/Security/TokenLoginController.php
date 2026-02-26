<?php

declare(strict_types=1);

namespace App\Controller\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;

final class TokenLoginController extends AbstractController
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
            $this->addFlash('danger', 'Ungültiger oder abgelaufener QR-Code.');
            return $this->redirectToRoute('app_login');
        }

        // 2. User manuell einloggen
        // "main" ist der Name deines Firewalls in der security.yaml
        $security->login($user, 'form_login', 'main');

        $this->addFlash('success', 'Willkommen, ' . $user->getFirstname() . '! Du bist jetzt eingeloggt.');

        // 3. Weiterleitung zur Teilnehmer-Übersicht (oder deinem Dashboard)
        return $this->redirectToRoute('app_participant_dashboard'); 
    }
}