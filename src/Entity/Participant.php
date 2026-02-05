<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\ParticipantRepository;

#[ORM\Entity(repositoryClass: ParticipantRepository::class)]
#[ORM\Table(name: 'sportabzeichen_participants')]
class Participant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Verknüpfung zum User (Hier holen wir uns Name, Klasse etc.)
    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'EAGER')] 
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'date', nullable: true, name: 'geburtsdatum')]
    private ?\DateTimeInterface $birthdate = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true, name: 'geschlecht')]
    private ?string $gender = null; 

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $origin = 'MANUAL'; // CSV_LINKED, ISERV_IMPORT etc.

    #[ORM\Column(type: 'datetime', nullable: true, name: 'updated_at')]
    private ?\DateTimeInterface $updatedAt = null;

    // Legacy Felder (falls noch benötigt, sonst ignorieren wir sie)
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $username = null; // Veraltet -> User entity nutzen

    #[ORM\Column(type: 'string', length: 255, nullable: true, name: 'group_name')]
    private ?string $legacyGroupName = null; // Veraltet -> User->Groups nutzen

    // Relationen
    #[ORM\OneToMany(mappedBy: 'participant', targetEntity: SwimmingProof::class, cascade: ['persist', 'remove'])]
    private Collection $swimmingProofs;

    #[ORM\OneToMany(mappedBy: 'participant', targetEntity: ExamParticipant::class, cascade: ['persist', 'remove'])]
    private Collection $examParticipants;

    public function __construct()
    {
        $this->swimmingProofs = new ArrayCollection();
        $this->examParticipants = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }

    public function getBirthdate(): ?\DateTimeInterface { return $this->birthdate; }
    public function setBirthdate(?\DateTimeInterface $birthdate): self { $this->birthdate = $birthdate; return $this; }
    // Alias für alten Code
    public function getGeburtsdatum(): ?\DateTimeInterface { return $this->birthdate; }

    public function getGender(): ?string { return $this->gender; }
    public function setGender(?string $gender): self { $this->gender = $gender; return $this; }
    // Alias für alten Code
    public function getGeschlecht(): ?string { return $this->gender; }

    public function getOrigin(): ?string { return $this->origin; }
    public function setOrigin(?string $origin): self { $this->origin = $origin; return $this; }

    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    /**
     * @return Collection<int, SwimmingProof>
     */
    public function getSwimmingProofs(): Collection { return $this->swimmingProofs; }

    /**
     * @return Collection<int, ExamParticipant>
     */
    public function getExamParticipants(): Collection { return $this->examParticipants; }

    // Legacy Getter/Setter
    public function getUsername(): ?string { return $this->username; }
    public function setUsername(?string $username): self { $this->username = $username; return $this; }

    public function __toString(): string
    {
        return $this->user ? (string)$this->user : ($this->username ?: 'Unbekannt');    
    }
}   