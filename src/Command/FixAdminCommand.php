<?php

namespace App\Command;

use App\Entity\User;
use App\Entity\Institution; // Wichtig!
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:fix-admin',
    description: 'Erstellt Admin und Institution (Schule) neu nach DB-Reset',
)]
class FixAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = 'admin@schule.de';
        $password = 'admin123';
        $schoolName = 'Musterschule';

        // ---------------------------------------------------------
        // 1. Institution (Schule) sicherstellen
        // ---------------------------------------------------------
        $instRepo = $this->entityManager->getRepository(Institution::class);
        $institution = $instRepo->findOneBy(['name' => $schoolName]);

        if (!$institution) {
            $output->writeln('<comment>Keine Institution gefunden. Erstelle "' . $schoolName . '"...</comment>');
            $institution = new Institution();
            $institution->setName($schoolName);
            // Falls du ein Feld "key" oder "identifier" in der Institution hast:
            // $institution->setKey('musterschule'); 
            
            $this->entityManager->persist($institution);
            // Wir flushen hier noch nicht, machen wir am Ende alles zusammen
        } else {
            $output->writeln('<info>Institution "' . $schoolName . '" gefunden (ID: ' . $institution->getId() . ').</info>');
        }

        // ---------------------------------------------------------
        // 2. User sicherstellen
        // ---------------------------------------------------------
        $userRepo = $this->entityManager->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $email]);

        if (!$user) {
            $output->writeln('<comment>User nicht gefunden. Erstelle neuen Admin...</comment>');
            $user = new User();
            $user->setEmail($email);
            $user->setFirstname('Super'); // Pflichtfelder füllen
            $user->setLastname('Admin');
        }

        // ---------------------------------------------------------
        // 3. Verknüpfung und Passwort
        // ---------------------------------------------------------
        
        // Institution zuweisen (WICHTIG!)
        $user->setInstitution($institution);
        
        // Admin-Rechte geben
        $user->setRoles(['ROLE_ADMIN']);

        // Passwort hashen
        $hashedPassword = $this->passwordHasher->hashPassword(
            $user,
            $password
        );
        $user->setPassword($hashedPassword);

        // ---------------------------------------------------------
        // 4. Speichern
        // ---------------------------------------------------------
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln('----------------------------------------');
        $output->writeln('<info>ERFOLGREICH WIEDERHERGESTELLT!</info>');
        $output->writeln('Institution: ' . $institution->getName() . ' (ID: ' . $institution->getId() . ')');
        $output->writeln('User:        ' . $email);
        $output->writeln('Passwort:    ' . $password);
        $output->writeln('----------------------------------------');

        return Command::SUCCESS;
    }
}