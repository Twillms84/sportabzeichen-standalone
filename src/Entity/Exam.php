<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ExamRepository;
use App\Entity\User; // <--- WICHTIG: User importieren
use App\Entity\Group;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Table(name: 'sportabzeichen_exams')]
#[ORM\Entity(repositoryClass: ExamRepository::class)]
class Exam
{
    // ... (id, name, year, date bleiben wie sie sind) ...
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, name: 'exam_name')]
    private ?string $name = null;

    #[ORM\Column(type: 'integer', name: 'exam_year')]
    private ?int $year = null;

    #[ORM\Column(type: 'date', nullable: true, name: 'exam_date')]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(type: 'string', nullable: true, name: 'creator_id')]
    private ?string $creator = null;

    public function getCreator(): ?string
    {
        return $this->creator;
    }

    public function setCreator(?string $creator): self
    {
        $this->creator = $creator;
        return $this;
    }
    // ... (Getter/Setter für id, name, year, date bleiben gleich) ...

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(int $year): self { $this->year = $year; return $this; }

    public function getDate(): ?\DateTimeInterface { return $this->date; }
    public function setDate(?\DateTimeInterface $date): self { $this->date = $date; return $this; }

    // ... (toString und getDisplayName bleiben gleich) ...
    public function __toString(): string 
    { 
        return $this->name ?? (string)$this->year; 
    }
    
    public function getDisplayName(): string
    {
        return 'Sportabzeichen ' . $this->year;
    }
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'sportabzeichen_exam_groups')] // <--- Das löst deinen Fehler!
    private Collection $groups;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
    }

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
}