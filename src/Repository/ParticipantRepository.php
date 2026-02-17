<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Participant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends ServiceEntityRepository<Participant>
 */
class ParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Participant::class);
    }

    public function getSearchQueryBuilder(string $searchTerm = ''): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')
            ->addSelect('u')
            // Jetzt joinen wir auch die Gruppen über den User!
            ->leftJoin('u.groups', 'g') 
            ->addSelect('g');

        if ($searchTerm !== '') {
            $qb->andWhere('
                LOWER(u.lastname) LIKE :q OR 
                LOWER(u.firstname) LIKE :q OR 
                LOWER(u.username) LIKE :q OR
                LOWER(g.name) LIKE :q  
            ')
            ->setParameter('q', '%' . mb_strtolower($searchTerm) . '%');
        }

        $qb->orderBy('u.lastname', 'ASC')
           ->addOrderBy('u.firstname', 'ASC');

        return $qb;
    }

    public function getAdminList(int $page = 1, int $limit = 20, ?string $search = null): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')
            ->addSelect('u') // Performance: User-Daten mitladen
            ->orderBy('u.lastname', 'ASC');

        if ($search) {
            $qb->andWhere('
                LOWER(u.lastname) LIKE LOWER(:q) OR 
                LOWER(u.firstname) LIKE LOWER(:q) OR 
                LOWER(u.email) LIKE LOWER(:q)
            ')
            ->setParameter('q', '%' . $search . '%');
        }

        $qb->setFirstResult(($page - 1) * $limit)
        ->setMaxResults($limit);

        return new Paginator($qb);
    }
}