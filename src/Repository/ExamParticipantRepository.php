<?php

namespace App\Repository;

use App\Entity\Exam;
use App\Entity\ExamParticipant;
use App\Entity\ExamResult; // <--- Wichtig: Deine Ergebnis-Entity
use App\Entity\Institution;
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
     * Findet alle Teilnehmer einer bestimmten Prüfung.
     * SICHER: Filtert zusätzlich nach der Institution!
     */
    public function findByExam(int $examId, Institution $institution): array
    {
        return $this->createQueryBuilder('ep')
            ->join('ep.exam', 'e')
            ->where('e.id = :examId') // where statt andWhere am Anfang ist sauberer, aber beides geht
            ->andWhere('e.institution = :institution')
            ->setParameter('examId', $examId)
            ->setParameter('institution', $institution)
            
            // Performance: Verwandte Daten gleich mitladen
            ->leftJoin('ep.participant', 'p')
            ->addSelect('p')
            ->leftJoin('p.user', 'u')
            ->addSelect('u')
            
            ->orderBy('u.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lädt die Einzelergebnisse (ExamResult) für die Statistik.
     * Wird vom ExamController::stats() verwendet.
     */
    public function findResultsForStats(Exam $exam): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            // Wir laden das Ergebnis (r) und joinen alles andere dazu
            ->select('r', 'ep', 'p', 'u', 'd')
            ->from(ExamResult::class, 'r') // <--- Hier nutzen wir die Klasse direkt, das ist sicherer als String
            
            ->innerJoin('r.examParticipant', 'ep')
            ->innerJoin('ep.participant', 'p')
            ->innerJoin('p.user', 'u')
            ->innerJoin('r.discipline', 'd')
            
            ->where('ep.exam = :exam')
            // Optional: Nur Ergebnisse > 0 Punkte anzeigen?
            // Wenn du auch 0-Punkte-Ergebnisse in der Liste willst, nimm die nächste Zeile raus:
            ->andWhere('r.points > 0') 
            
            ->setParameter('exam', $exam)
            ->getQuery()
            ->getResult();
    }
}