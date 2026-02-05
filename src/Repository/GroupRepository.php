<?php

namespace App\Repository;

use App\Entity\Group;
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

    public function findAllSorted()
    {
        // Wir sortieren nach 'name' (z.B. "Klasse 5a") oder 'act' (interner ID)
        return $this->findBy([], ['name' => 'ASC']); 
    }
}