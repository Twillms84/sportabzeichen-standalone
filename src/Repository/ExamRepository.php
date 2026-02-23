<?php

namespace App\Repository;

use App\Entity\Exam;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Exam>
 */
class ExamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exam::class);
    }

    /**
     * Findet User, die in Gruppen der Prüfung sind, aber noch nicht teilnehmen.
     */
    public function findMissingUsersForExam(Exam $exam, string $search = ''): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        $qb->select('u')
        ->from(User::class, 'u')
        // 1. Join zu Participant (Stammdaten)
        ->innerJoin('u.participant', 'p')
        // 2. Join zu den Gruppen des Users
        ->innerJoin('u.groups', 'g')
        // 3. WICHTIG: Filtere Gruppen, die dieser Prüfung zugewiesen sind
        ->innerJoin('g.exams', 'e', 'WITH', 'e.id = :examId')
        // 4. Prüfe, ob der User bereits in der Teilnehmerliste dieser Prüfung steht
        ->leftJoin('p.examParticipants', 'ep', 'WITH', 'ep.exam = :exam')
        
        ->where('ep.id IS NULL'); // Nur die, die noch nicht registriert sind

        if ($search !== '') {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(u.lastname) LIKE :search',
                'LOWER(u.firstname) LIKE :search'
            ))
            ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        return $qb->setParameter('exam', $exam)
                ->setParameter('examId', $exam->getId())
                ->orderBy('u.lastname', 'ASC')
                ->addOrderBy('u.firstname', 'ASC')
                ->getQuery()
                ->getResult();
    }
}