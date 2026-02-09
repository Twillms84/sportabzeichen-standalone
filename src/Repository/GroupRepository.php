<?php

namespace App\Repository;

use App\Entity\Group;
use App\Entity\Institution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Group>
 */
class GroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Group::class);
    }

    /**
     * Findet Gruppen NUR für die angegebene Institution, sortiert nach Name.
     * @return Group[]
     */
    public function findByInstitution(Institution $institution): array
    {
        return $this->findBy(
            ['institution' => $institution], 
            ['name' => 'ASC']
        );
    }
    
    // Optional: Suchfunktion für Gruppen innerhalb einer Schule
    public function searchByInstitution(Institution $institution, string $term): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.institution = :institution')
            ->andWhere('g.name LIKE :term OR g.act LIKE :term')
            ->setParameter('institution', $institution)
            ->setParameter('term', '%' . $term . '%')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}