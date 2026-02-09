<?php

namespace App\Command;

use App\Entity\User;
use App\Entity\Institution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:fix-admin',
    description: 'Erstellt Admin und Institution basierend auf Registrar-Email',
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
        // --- KONFIGURATION ---
        // Das ist jetzt der "Master-Key": Die E-Mail des Registrierers
        $adminEmail = 'admin@schule.de'; 
        
        $password = 'admin123';
        $schoolName = 'Musterschule';
        $schoolType = 'SCHOOL'; 

        // ---------------------------------------------------------
        // 1. Institution über Registrar-Email suchen
        // ---------------------------------------------------------
        $instRepo = $this->entityManager->getRepository(Institution::class);
        
        // Die entscheidende Änderung: Wir suchen nach der E-Mail des Besitzers
        $institution = $instRepo->findOneBy(['registrarEmail' => $adminEmail]);

        if (!$institution) {
            $output->writeln('<comment>Keine Institution für "' . $adminEmail . '" gefunden. Erstelle neu...</comment>');
            
            $institution = new Institution();
            $institution->setName($schoolName);
            $institution->setRegistrarEmail($adminEmail); // Hier wird die Verknüpfung gesetzt
            $institution->setType($schoolType);
            
            // Optional: Identifier für später (kann auch null sein erstmal)
            $institution->setIdentifier('auto-' . uniqid()); 
            
            $this->entityManager->persist($institution);
        } else {
            $output->writeln('<info>Institution gefunden: ' . $institution->getName() . ' (gehört: ' . $adminEmail . ')</info>');
        }

        // ---------------------------------------------------------
        // 2. User sicherstellen (Der Admin selbst)
        // ---------------------------------------------------------
        $userRepo = $this->entityManager->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $adminEmail]);

        if (!$user) {
            $output->writeln('<comment>User Account existiert noch nicht. Erstelle...</comment>');
            $user = new User();
            $user->setEmail($adminEmail);
            $user->setFirstname('Super');
            $user->setLastname('Admin');
        }

        // ---------------------------------------------------------
        // 3. Verknüpfung
        // ---------------------------------------------------------
        
        // Den User der Institution zuweisen, die ihm gehört
        $user->setInstitution($institution);
        
        $user->setRoles(['ROLE_ADMIN']);

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
        $output->writeln('<info>SETUP ABGESCHLOSSEN</info>');
        $output->writeln('Registrar:   ' . $adminEmail);
        $output->writeln('Institution: ' . $institution->getName());
        $output->writeln('----------------------------------------');

        return Command::SUCCESS;
    }
}