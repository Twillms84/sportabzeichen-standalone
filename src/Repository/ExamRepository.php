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
        // 1. Wir brauchen zwingend ein Participant-Profil (wegen birthdate)
        ->innerJoin(\App\Entity\Participant::class, 'p', 'WITH', 'p.user = u')
        // 2. Wir prüfen, ob der User schon in DIESER Prüfung ist
        ->leftJoin('p.examParticipants', 'ep', 'WITH', 'ep.exam = :exam')
        // 3. Filter: Gleiche Institution wie die Prüfung
        ->where('u.institution = :institution')
        // 4. Nur User, die noch NICHT in der Prüfung sind (Ausschluss-Logik)
        ->andWhere('ep.id IS NULL');

        if ($search !== '') {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(u.lastname) LIKE :search',
                'LOWER(u.firstname) LIKE :search'
            ))
            ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        return $qb->setParameter('exam', $exam)
                ->setParameter('institution', $exam->getInstitution())
                ->orderBy('u.lastname', 'ASC')
                ->addOrderBy('u.firstname', 'ASC')
                ->setMaxResults(300)
                ->getQuery()
                ->getResult();
    }
}