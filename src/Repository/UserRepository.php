<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use App\Entity\Institution;

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
    public function findStaffByInstitution($institution): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.institution = :inst')
            ->setParameter('inst', $institution)
            // Wir casten das JSON-Feld zu TEXT für den LIKE-Vergleich
            ->andWhere('CAST(u.roles AS text) LIKE :roleAdmin OR CAST(u.roles AS text) LIKE :roleExaminer')
            ->setParameter('roleAdmin', '%"ROLE_ADMIN"%')
            ->setParameter('roleExaminer', '%"ROLE_EXAMINER"%')
            // Super-Admins ausschließen
            ->andWhere('CAST(u.roles AS text) NOT LIKE :roleSuper')
            ->setParameter('roleSuper', '%"ROLE_SUPER_ADMIN"%')
            ->orderBy('u.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByRole(string $role, ?Institution $institution = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"' . $role . '"%'); // JSON Suche mit Anführungszeichen für Exaktheit

        // Wenn eine Institution übergeben wurde, filtern wir zusätzlich
        if ($institution) {
            // ANPASSEN: Je nachdem wie deine Relation heißt (z.B. 'institution' oder 'institutions')
            
            // FALL A: User gehört zu GENAU EINER Institution (Many-to-One)
            $qb->andWhere('u.institution = :institution')
               ->setParameter('institution', $institution);

            // FALL B: User kann MEHREREN Institutionen angehören (Many-to-Many)
            // $qb->andWhere(':institution MEMBER OF u.institutions')
            //    ->setParameter('institution', $institution);
        }

        return $qb->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->getQuery()
            ->getResult();
    }
}