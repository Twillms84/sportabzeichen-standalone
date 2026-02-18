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
     * Lädt alle Prüfungen inkl. Prüfer und Teilnehmer für die Übersicht.
     * Optimiert, um Datenbankabfragen zu sparen.
     * * @return Exam[]
     */
    public function findAllWithStats(): array
    {
        return $this->createQueryBuilder('e')
            ->addSelect('ex') // Examiner (User)
            ->addSelect('ep') // ExamParticipants
            ->addSelect('p')  // Participant (Stammdaten)
            
            ->leftJoin('e.examiner', 'ex')
            ->leftJoin('e.examParticipants', 'ep')
            ->leftJoin('ep.participant', 'p')
            
            ->orderBy('e.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // ... deine existierende findMissingUsersForExam Methode ...
    public function findMissingUsersForExam(Exam $exam, string $search = ''): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        $qb->select('u')
        ->from(User::class, 'u')
        ->join('u.groups', 'g')
        ->join(Exam::class, 'e', 'WITH', 'g MEMBER OF e.groups')
        ->leftJoin('u.participant', 'p')
        ->leftJoin('p.examParticipants', 'ep', 'WITH', 'ep.exam = :exam')
        
        ->where('e.id = :examId')
        ->andWhere('ep.id IS NULL');

        if ($search) {
            $qb->andWhere('u.lastname LIKE :search OR u.firstname LIKE :search')
            ->setParameter('search', '%' . $search . '%');
        }

        return $qb->setParameter('exam', $exam)
                ->setParameter('examId', $exam->getId())
                ->orderBy('u.lastname', 'ASC')
                ->addOrderBy('u.firstname', 'ASC')
                ->setMaxResults(300)
                ->getQuery()
                ->getResult();
    }
}