<?php

namespace App\Repository;

use App\Entity\ExamParticipant;
use App\Entity\Institution; // <--- Importieren
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExamParticipant>
 */
class ExamParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamParticipant::class);
    }

    /**
     * Findet alle Teilnehmer einer bestimmten Prüfung
     * SICHER: Filtert zusätzlich nach der Institution!
     */
    public function findByExam(int $examId, Institution $institution): array
    {
        return $this->createQueryBuilder('ep')
            ->join('ep.exam', 'e') // Wir müssen das Exam joinen, um die Schule zu prüfen
            ->andWhere('e.id = :examId')
            ->andWhere('e.institution = :institution') // <--- DER SICHERHEITS-CHECK
            ->setParameter('examId', $examId)
            ->setParameter('institution', $institution)
            
            ->leftJoin('ep.participant', 'p')
            ->addSelect('p')
            ->leftJoin('p.user', 'u')
            ->addSelect('u')
            
            ->orderBy('u.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }
    public function findResultsForStats(Exam $exam): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('r', 'ep', 'p', 'u', 'd')
            ->from('App\Entity\ExamResult', 'r')
            ->join('r.examParticipant', 'ep')
            ->join('ep.participant', 'p')
            ->join('p.user', 'u')
            ->join('r.discipline', 'd')
            ->where('ep.exam = :exam')
            ->andWhere('r.points > 0')
            ->setParameter('exam', $exam)
            ->getQuery()
            ->getResult();
    }
}