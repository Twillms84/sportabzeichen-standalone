<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:fix-admin',
    description: 'Setzt das Admin Passwort neu (Sauberer Weg)',
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

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user) {
            $output->writeln('<error>User ' . $email . ' nicht gefunden!</error>');
            return Command::FAILURE;
        }

        // Das ist der entscheidende Teil: Der offizielle Hasher
        $hashedPassword = $this->passwordHasher->hashPassword(
            $user,
            $password
        );

        $user->setPassword($hashedPassword);
        $this->entityManager->flush();

        $output->writeln('<info>ERFOLG!</info>');
        $output->writeln('User: ' . $email);
        $output->writeln('Pass: ' . $password);
        $output->writeln('Hash: ' . $hashedPassword);

        return Command::SUCCESS;
    }
}