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

        return $qb->select('u')
            ->from(\App\Entity\User::class, 'u')
            // 1. Wir brauchen das Participant-Profil (wegen Geburtsdatum)
            ->innerJoin('u.participant', 'p')
            // 2. Wir joinen die Gruppen des Users
            ->innerJoin('u.groups', 'g')
            // 3. WICHTIG: Wir filtern die Gruppen, die im Exam hinterlegt sind
            // Da Exam -> Groups existiert, nutzen wir MEMBER OF
            ->where(':exam MEMBER OF g.exams') // Falls Group -> Exam ManyToMany
            // ODER (wahrscheinlicher basierend auf deinem vorherigen Code):
            ->andWhere('g MEMBER OF :examGroups')
            
            // 4. Nur Teilnehmer, die noch nicht in dieser Prüfung sind
            ->leftJoin('p.examParticipants', 'ep', 'WITH', 'ep.exam = :exam')
            ->andWhere('ep.id IS NULL')
            
            ->setParameter('exam', $exam)
            ->setParameter('examGroups', $exam->getGroups()) // Wir übergeben die Collection der Prüfungsgruppen
            
            // Suche und Sortierung
            ->orderBy('u.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }
}