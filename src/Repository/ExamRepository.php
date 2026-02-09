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
           ->join('u.groups', 'g')           // User hat Gruppen
           ->join('g.exams', 'e')            // Gruppen sind der Prüfung zugeordnet
           ->leftJoin('u.participant', 'p')  // User hat (vielleicht) einen Participant-Eintrag
           ->leftJoin('p.examParticipants', 'ep', 'WITH', 'ep.exam = :exam') // Participant ist in DIESER Prüfung
           
           ->where('e.id = :examId')
           ->andWhere('ep.id IS NULL')       // WICHTIG: Er darf NICHT in der Prüfung sein
           ->andWhere('u.deleted IS NULL');

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