<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 * @implements PasswordUpgraderInterface<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Wird verwendet, um Passwörter automatisch neu zu hashen, wenn sich der Algorithmus ändert.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    // --- DEINE BESTEHENDE FUNKTION (BLEIBT ERHALTEN) ---
    // Hilfsmethode für den Import, um User schnell zu finden
    public function findByImportIdOrAct(string $identifier): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.importId = :val')
            ->orWhere('u.act = :val')
            ->setParameter('val', $identifier)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // --- DIE NEUE FUNKTION FÜR DIE PRÜFER-LISTE (NEU) ---
    /**
     * Findet alle User, die Admin oder Prüfer sind (filtert Schüler raus).
     * @return User[]
     */
    public function findStaff(?Institution $institution = null): array
    {
        $qb = $this->createQueryBuilder('u')
            // Suche nach Rollen Admin oder Prüfer
            ->where('u.roles LIKE :roleAdmin OR u.roles LIKE :roleExaminer')
            ->setParameter('roleAdmin', '%"ROLE_ADMIN"%')
            ->setParameter('roleExaminer', '%"ROLE_EXAMINER"%')
            ->orderBy('u.lastname', 'ASC');

        // WICHTIG: Filterung auf Institution (statt School)
        if ($institution) {
            $qb->andWhere('u.institution = :institution')
               ->setParameter('institution', $institution);
        }

        return $qb->getQuery()->getResult();
    }
}