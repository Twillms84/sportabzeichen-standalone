<?php

namespace App\Entity;

use App\Repository\InstitutionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstitutionRepository::class)]
class Institution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    // --- WICHTIG: Die E-Mail des Erstellers (Besitzer) ---
    // Dient als Haupt-Identifikator bei der Registrierung
    #[ORM\Column(length: 180, unique: true)] 
    private ?string $registrarEmail = null;

    // --- OPTIONAL: Technischer Identifier (z.B. für IServ/SSO später) ---
    #[ORM\Column(length: 100, unique: true, nullable: true)] 
    private ?string $identifier = null;

    // --- BASIS DATEN ---

    #[ORM\Column(length: 50)]
    private ?string $type = null; // z.B. 'SCHOOL', 'CLUB'

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactPerson = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $city = null;

    // --- BEZIEHUNGEN ---

    #[ORM\OneToMany(mappedBy: 'institution', targetEntity: Group::class, orphanRemoval: true)]
    private Collection $groups;

    #[ORM\OneToMany(mappedBy: 'institution', targetEntity: User::class)]
    private Collection $users;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
        $this->users = new ArrayCollection();
    }

    #[ORM\OneToMany(mappedBy: 'institution', targetEntity: Group::class, orphanRemoval: true, cascade: ['remove'])]
    private Collection $groups;

    // --- GETTER & SETTER ---

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getRegistrarEmail(): ?string
    {
        return $this->registrarEmail;
    }

    public function setRegistrarEmail(string $registrarEmail): static
    {
        $this->registrarEmail = $registrarEmail;
        return $this;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(?string $identifier): static
    {
        $this->identifier = $identifier;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): static
    {
        $this->contactPerson = $contactPerson;
        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): static
    {
        $this->street = $street;
        return $this;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function setZip(?string $zip): static
    {
        $this->zip = $zip;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;
        return $this;
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
            $group->setInstitution($this);
        }
        return $this;
    }

    public function removeGroup(Group $group): static
    {
        if ($this->groups->removeElement($group)) {
            if ($group->getInstitution() === $this) {
                $group->setInstitution(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setInstitution($this);
        }
        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            if ($user->getInstitution() === $this) {
                $user->setInstitution(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}