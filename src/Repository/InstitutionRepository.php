<?php

namespace App\Repository;

use App\Entity\Institution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Institution>
 */
class InstitutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Institution::class);
    }

    public function findAllWithAdmins(): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.users', 'u')
            ->addSelect('u')
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}