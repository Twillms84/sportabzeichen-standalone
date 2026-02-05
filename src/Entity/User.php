<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Table(name: 'users')]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true, nullable: true)]
    private ?string $username = null;

    // IServ Account ID (Login Name)
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $act = null; 

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firstname = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastname = null;

    // 'iserv' oder 'csv'
    #[ORM\Column(length: 50, options: ['default' => 'iserv'])]
    private ?string $source = 'iserv';

    // Die ID aus der CSV (früher externalId genannt, im SQL aber import_id)
    #[ORM\Column(name: 'import_id', length: 255, nullable: true, unique: true)]
    private ?string $importId = null; 
    
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    // --- RELATIONEN ---

    // Die wichtige Verbindung zu den Gruppen (Tabelle users_groups)
    #[ORM\ManyToMany(targetEntity: Group::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'users_groups')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'group_id', referencedColumnName: 'id')]
    private Collection $groups;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
    }

    // --- Getter & Setter ---

    public function getId(): ?int { return $this->id; }

    public function getUsername(): ?string { return $this->username; }
    public function setUsername(?string $username): self { $this->username = $username; return $this; }

    public function getUserIdentifier(): string { 
        // Fallback: Wenn kein Username (IServ) da ist, nehmen wir die importId oder den Namen
        return (string) ($this->username ?? $this->importId ?? 'user_'.$this->id); 
    }

    public function getAct(): ?string { return $this->act; }
    public function setAct(?string $act): self { $this->act = $act; return $this; }

    public function getFirstname(): ?string { return $this->firstname; }
    public function setFirstname(?string $firstname): self { $this->firstname = $firstname; return $this; }

    public function getLastname(): ?string { return $this->lastname; }
    public function setLastname(?string $lastname): self { $this->lastname = $lastname; return $this; }

    public function getSource(): ?string { return $this->source; }
    public function setSource(string $source): self { $this->source = $source; return $this; }

    public function getImportId(): ?string { return $this->importId; }
    public function setImportId(?string $importId): self { $this->importId = $importId; return $this; }

    /**
     * @return Collection<int, Group>
     */
    public function getGroups(): Collection { return $this->groups; }
    
    public function addGroup(Group $group): self
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
        }
        return $this;
    }

    public function removeGroup(Group $group): self
    {
        $this->groups->removeElement($group);
        return $this;
    }

    public function getRoles(): array { 
        $roles = $this->roles; 
        $roles[] = 'ROLE_USER'; 
        return array_unique($roles); 
    }
    public function setRoles(array $roles): self { $this->roles = $roles; return $this; }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): self { $this->password = $password; return $this; }

    public function eraseCredentials(): void {}
    
    public function __toString(): string { 
        if ($this->firstname && $this->lastname) {
            return $this->firstname . ' ' . $this->lastname;
        }
        return $this->username ?? (string)$this->id; 
    }
}