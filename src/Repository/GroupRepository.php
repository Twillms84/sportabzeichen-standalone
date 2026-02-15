<?php

namespace App\Repository;

use App\Entity\Group;
use App\Entity\Institution;
use App\Entity\Exam;
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
    /**
     * Findet IDs von Gruppen, die in einem bestimmten Jahr bereits einer Prüfung zugewiesen sind.
     * @return int[]
     */
    public function findGroupIdsUsedInYear(Institution $institution, int $year): array
    {
        // LÖSUNG: Wir fragen nicht die Gruppe ab, sondern starten beim Exam!
        // Da das Exam die Relation "groups" besitzt, können wir von dort joinen.
        
        $qb = $this->getEntityManager()->createQueryBuilder();

        $result = $qb->select('g.id')
            ->from(Exam::class, 'e')    // Wir starten bei Exam
            ->join('e.groups', 'g')     // Und gehen zu den Gruppen (das Feld 'groups' existiert im Exam)
            ->where('e.institution = :institution')
            ->andWhere('e.year = :year')
            ->setParameter('institution', $institution)
            ->setParameter('year', $year)
            ->getQuery()
            ->getScalarResult();

        return array_column($result, 'id');
    }
}