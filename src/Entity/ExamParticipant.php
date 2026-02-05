<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExamParticipantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExamParticipantRepository::class)]
#[ORM\Table(name: 'sportabzeichen_exam_participants')]
#[ORM\UniqueConstraint(name: 'uniq_exam_participant', columns: ['exam_id', 'participant_id'])]
class ExamParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Exam::class)]
    #[ORM\JoinColumn(name: 'exam_id', nullable: false, onDelete: 'CASCADE')]
    private ?Exam $exam = null;

    // WICHTIG: In der Entity 'Participant' muss nun stehen:
    // #[ORM\OneToMany(mappedBy: 'examParticipant', targetEntity: ExamParticipant::class)]
    // private Collection $examParticipants;
    #[ORM\ManyToOne(targetEntity: Participant::class, inversedBy: 'examParticipants')]
    #[ORM\JoinColumn(name: 'participant_id', nullable: false, onDelete: 'CASCADE')]
    private ?Participant $participant = null;

    // Das Alter im Jahr der Prüfung (wichtig für die Berechnung der Anforderungen)
    #[ORM\Column(type: 'integer', name: 'age_year')]
    private ?int $age = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0], name: 'total_points')]
    private int $totalPoints = 0;

    // 'NONE', 'BRONZE', 'SILBER', 'GOLD'
    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'NONE'], name: 'final_medal')]
    private string $finalMedal = 'NONE';

    // Relation zu den Einzelergebnissen (Laufen, Springen, etc.)
    #[ORM\OneToMany(mappedBy: 'examParticipant', targetEntity: ExamResult::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $results;

    public function __construct()
    {
        $this->results = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getExam(): ?Exam { return $this->exam; }
    public function setExam(?Exam $exam): self { $this->exam = $exam; return $this; }

    public function getParticipant(): ?Participant { return $this->participant; }
    public function setParticipant(?Participant $participant): self { $this->participant = $participant; return $this; }

    // Hilfsmethode für Templates
    public function getExamYear(): ?int { return $this->exam?->getYear(); }

    public function getAge(): ?int { return $this->age; }
    public function setAge(int $age): self { $this->age = $age; return $this; }
    
    // Alias, falls alter Code getAgeYear aufruft
    public function getAgeYear(): ?int { return $this->age; }

    public function getTotalPoints(): int { return $this->totalPoints; }
    public function setTotalPoints(int $totalPoints): self { $this->totalPoints = $totalPoints; return $this; }

    public function getFinalMedal(): string { return $this->finalMedal; }
    public function setFinalMedal(string $finalMedal): self { $this->finalMedal = $finalMedal; return $this; }

    /**
     * @return Collection<int, ExamResult>
     */
    public function getResults(): Collection { return $this->results; }

    public function addResult(ExamResult $result): self
    {
        if (!$this->results->contains($result)) {
            $this->results->add($result);
            $result->setExamParticipant($this);
        }
        return $this;
    }

    public function removeResult(ExamResult $result): self
    {
        if ($this->results->removeElement($result)) {
            if ($result->getExamParticipant() === $this) {
                $result->setExamParticipant(null);
            }
        }
        return $this;
    }
}