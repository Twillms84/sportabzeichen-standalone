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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Hausmeister gestartet: Räume unbestätigte User auf...');

        // Zeitpunkt vor 24 Stunden berechnen
        $limitDate = new \DateTimeImmutable('-24 hours');

        // Alle User suchen, die nicht verifiziert sind UND älter als 24h sind
        // (Für den Test kannst du '-24 hours' oben im Code kurz in '-1 minute' ändern!)
        $oldUnverifiedUsers = $this->userRepository->createQueryBuilder('u')
            ->where('u.isVerified = false')
            ->andWhere('u.createdAt < :limitDate')
            ->setParameter('limitDate', $limitDate)
            ->getQuery()
            ->getResult();

        if (empty($oldUnverifiedUsers)) {
            $io->success('Alles sauber! Keine alten unbestätigten User gefunden.');
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($oldUnverifiedUsers as $user) {
            // Die Institution gleich mit löschen, damit keine "Geister-Schulen" übrig bleiben
            $institution = $user->getInstitution();
            
            $this->entityManager->remove($user);
            if ($institution) {
                $this->entityManager->remove($institution);
            }
            $count++;
        }

        // Änderungen in die Datenbank schreiben
        $this->entityManager->flush();

        $io->success(sprintf('Fertig! %d Karteileiche(n) erfolgreich gelöscht.', $count));

        return Command::SUCCESS;
    }
}