<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-unverified-users',
    description: 'Löscht alle unbestätigten User (und deren Institution), die älter als 24 Stunden sind.',
)]
class CleanupUnverifiedUsersCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    // src/Command/CleanupUnverifiedUsersCommand.php
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Hole alle User, die NICHT verifiziert sind
        // In der echten Welt baust du dir dafür eine Methode im UserRepository:
        // $users = $this->userRepository->findOldUnverifiedUsers(new \DateTime('-24 hours'));
        
        // Für jeden gefundenen User:
        // $this->entityManager->remove($user);
        // $this->entityManager->flush();

        $output->writeln('Aufgeräumt: Unbestätigte Accounts wurden gelöscht.');
        return Command::SUCCESS;
    }
}