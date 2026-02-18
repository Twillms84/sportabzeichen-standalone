<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
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
    public function findAvailableExaminers(?Institution $institution = null): array
    {
        $entityManager = $this->getEntityManager();
        $rsm = new ResultSetMappingBuilder($entityManager);
        $rsm->addRootEntityFromClassMetadata(User::class, 'u');

        $tableName = $this->getClassMetadata()->getTableName();

        // Wir suchen nach allen Rollen, die prüfen dürfen. 
        // WICHTIG: Das NOT LIKE ROLE_SUPER_ADMIN habe ich entfernt, damit DU auftauchst!
        $sql = "SELECT " . $rsm->generateSelectClause() . "
                FROM " . $tableName . " u
                WHERE (u.roles::text LIKE :r1 OR u.roles::text LIKE :r2 OR u.roles::text LIKE :r3)";

        if ($institution) {
            $sql .= " AND u.institution_id = :instId";
        }

        $sql .= " ORDER BY u.lastname ASC, u.firstname ASC";

        $query = $entityManager->createNativeQuery($sql, $rsm);
        $query->setParameter('r1', '%"ROLE_EXAMINER"%');
        $query->setParameter('r2', '%"ROLE_ADMIN"%');
        $query->setParameter('r3', '%"ROLE_SUPER_ADMIN"%');

        if ($institution) {
            $query->setParameter('instId', $institution->getId());
        }

        return $query->getResult();
    }

    public function findByRole(string $role, ?Institution $institution = null): array
    {
        $entityManager = $this->getEntityManager();
        
        // 1. Mapping Builder: Damit Doctrine weiß, wie das SQL-Ergebnis in User-Objekte umgewandelt wird
        $rsm = new ResultSetMappingBuilder($entityManager);
        $rsm->addRootEntityFromClassMetadata(User::class, 'u');

        // 2. Tabellennamen dynamisch holen (falls er nicht "user" heißt)
        $tableName = $this->getClassMetadata()->getTableName();

        // 3. Native SQL Query bauen
        // WICHTIG: "u.roles::text" ist der Postgres-Trick, um JSON als Text durchsuchbar zu machen
        $sql = "SELECT " . $rsm->generateSelectClause() . "
                FROM " . $tableName . " u
                WHERE u.roles::text LIKE :role";

        // Optional: Filter nach Institution
        if ($institution) {
            // Annahme: Die Spalte in der DB heißt 'institution_id'. 
            // Falls sie anders heißt, muss das hier angepasst werden.
            $sql .= " AND u.institution_id = :instId";
        }

        $sql .= " ORDER BY u.lastname ASC, u.firstname ASC";

        // 4. Query erstellen
        $query = $entityManager->createNativeQuery($sql, $rsm);
        
        // Parameter setzen (JSON Suche braucht Anführungszeichen)
        $query->setParameter('role', '%"' . $role . '"%');

        if ($institution) {
            $query->setParameter('instId', $institution->getId());
        }

        return $query->getResult();
    }
}