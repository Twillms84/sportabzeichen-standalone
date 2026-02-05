<?php

namespace App\Repository;

use App\Entity\ExamParticipant;
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
     * Findet alle Teilnehmer einer bestimmten Prüfung (Jahr)
     */
    public function findByExam(int $examId): array
    {
        return $this->createQueryBuilder('ep')
            ->andWhere('ep.exam = :examId')
            ->setParameter('examId', $examId)
            ->leftJoin('ep.participant', 'p') // Join für Performance
            ->addSelect('p')
            ->leftJoin('p.user', 'u') // Join bis zum User für Namen
            ->addSelect('u')
            ->orderBy('u.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }
}