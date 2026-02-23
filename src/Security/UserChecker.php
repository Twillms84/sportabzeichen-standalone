<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Hier ist die Magie: Wenn der User nicht verifiziert ist, wird der Login abgebrochen!
        if (!$user->isVerified()) {
            throw new CustomUserMessageAccountStatusException('Bitte bestätige zuerst deine E-Mail-Adresse! Schau in dein Postfach.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // Wird nach der Passworteingabe aufgerufen - brauchen wir hierfür nicht.
    }
}