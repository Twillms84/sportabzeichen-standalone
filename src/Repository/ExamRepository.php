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
            ->from(\App\Entity\User::class, 'u')
            // 1. Wir brauchen das Participant-Profil (wegen birthdate)
            ->innerJoin('u.participant', 'p')
            // 2. Join zu den Gruppen des Users
            ->innerJoin('u.groups', 'g')
            // 3. WICHTIG: Wir joinen die Prüfung über deren Gruppen-Relation
            // Wir suchen also Gruppen, die in der "groups" Collection dieses Exams sind
            ->innerJoin(\App\Entity\Exam::class, 'e', 'WITH', 'g MEMBER OF e.groups')
            // 4. Ausschluss-Logik: Nur Teilnehmer, die noch nicht in dieser Prüfung sind
            ->leftJoin('p.examParticipants', 'ep', 'WITH', 'ep.exam = :exam')
            
            ->where('e.id = :examId')
            ->andWhere('ep.id IS NULL');

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