<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ExamRepository;
use App\Entity\User; 
use App\Entity\Group;
use App\Entity\ExamParticipant;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Table(name: 'sportabzeichen_exams')]
#[ORM\Entity(repositoryClass: ExamRepository::class)]
class Exam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)] // True lassen, damit alte Daten nicht crashen
    private ?User $examiner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Institution $institution = null;

    #[ORM\Column(type: 'string', length: 255, name: 'exam_name')]
    private ?string $name = null;

    #[ORM\Column(type: 'integer', name: 'exam_year')]
    private ?int $year = null;

    #[ORM\Column(type: 'date', nullable: true, name: 'exam_date')]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(type: 'string', nullable: true, name: 'creator_id')]
    private ?string $creator = null;

    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'sportabzeichen_exam_groups')]
    private Collection $groups;

    #[ORM\OneToMany(mappedBy: 'exam', targetEntity: ExamParticipant::class, cascade: ['persist', 'remove'])]
    private Collection $examParticipants;
    // -----------------------------------------------------------------------

    public function __construct()
    {
        $this->groups = new ArrayCollection();
        $this->examParticipants = new ArrayCollection();
    }

    public function getId(): ?int 
    { 
        return $this->id; 
    }

    public function getInstitution(): ?Institution
    {
        return $this->institution;
    }

    public function setInstitution(?Institution $institution): static
    {
        $this->institution = $institution;
        return $this;
    }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(int $year): self { $this->year = $year; return $this; }

    public function getDate(): ?\DateTimeInterface { return $this->date; }
    public function setDate(?\DateTimeInterface $date): self { $this->date = $date; return $this; }

    public function getCreator(): ?string
    {
        return $this->creator;
    }

    public function setCreator(?string $creator): self
    {
        $this->creator = $creator;
        return $this;
    }

    public function getExaminer(): ?User
    {
        return $this->examiner;
    }

    public function setExaminer(?User $examiner): static
    {
        $this->examiner = $examiner;

        return $this;
    }

    // --- Methoden für Groups ---

    /**
     * @return Collection<int, Group>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(Group $group): static
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
        }
        return $this;
    }

    public function removeGroup(Group $group): static
    {
        $this->groups->removeElement($group);
        return $this;
    }

    // --- NEU: Methoden für ExamParticipants ---

    /**
     * @return Collection<int, ExamParticipant>
     */
    public function getExamParticipants(): Collection
    {
        return $this->examParticipants;
    }

    public function addExamParticipant(ExamParticipant $examParticipant): static
    {
        if (!$this->examParticipants->contains($examParticipant)) {
            $this->examParticipants->add($examParticipant);
            $examParticipant->setExam($this);
        }

        return $this;
    }

    public function removeExamParticipant(ExamParticipant $examParticipant): static
    {
        if ($this->examParticipants->removeElement($examParticipant)) {
            // set the owning side to null (unless already changed)
            if ($examParticipant->getExam() === $this) {
                $examParticipant->setExam(null);
            }
        }

        return $this;
    }
    // ------------------------------------------

    public function __toString(): string 
    { 
        return $this->name ?? (string)$this->year; 
    }
    
    public function getDisplayName(): string
    {
        return 'Sportabzeichen ' . $this->year;
    }
}