<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Requirement;
use App\Entity\Discipline;

/**
 * @extends ServiceEntityRepository<Requirement>
 */
class RequirementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Requirement::class);
    }

    /**
     * Findet die passende Anforderung basierend auf Disziplin, Jahr, Geschlecht und Alter.
     */
    public function findMatchingRequirement(Discipline $discipline, int $year, string $gender, int $age): ?Requirement
    {
        return $this->createQueryBuilder('r')
            ->where('r.discipline = :disc')
            ->andWhere('r.year = :jahr')
            ->andWhere('r.gender = :gender')
            ->andWhere(':age BETWEEN r.minAge AND r.maxAge')
            // Hier folgen jetzt einfach die einzelnen Aufrufe:
            ->setParameter('disc', $discipline)
            ->setParameter('jahr', $year)
            ->setParameter('gender', $gender)
            ->setParameter('age', $age) // Hier KEIN "])" mehr!
            ->getQuery()
            ->getOneOrNullResult();
    }
}