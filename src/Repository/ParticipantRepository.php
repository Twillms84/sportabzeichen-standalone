<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Participant;

/**
 * @extends ServiceEntityRepository<Participant>
 */
class ParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Participant::class);
    }

    public function getSearchQueryBuilder(string $searchTerm = ''): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')
            ->addSelect('u'); // Eager Loading

        if ($searchTerm !== '') {
            $qb->andWhere('LOWER(u.lastname) LIKE :q OR LOWER(u.firstname) LIKE :q OR LOWER(u.username) LIKE :q')
               ->setParameter('q', '%' . mb_strtolower($searchTerm) . '%');
        }

        $qb->orderBy('u.lastname', 'ASC')
           ->addOrderBy('u.firstname', 'ASC');

        return $qb;
    }
}