<?php

namespace App\Entity;

use App\Repository\UserRepository;
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

    #[ORM\Column(length: 180, unique: true)]
    private ?string $username = null;

    // --- NEU: IServ-Kompatibilität ---
    // Das Feld 'act' ist bei IServ die Account-ID. Dein Legacy-Code nutzt das für Joins.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $act = null; 

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firstname = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastname = null;
    // ---------------------------------

    #[ORM\Column(length: 50)]
    private ?string $origin = 'MANUAL';

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $externalId = null; 
    
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    // --- Getter & Setter ---

    public function getId(): ?int { return $this->id; }

    public function getUsername(): ?string { return $this->username; }
    public function setUsername(string $username): self { $this->username = $username; return $this; }

    public function getUserIdentifier(): string { return (string) $this->username; }

    // --- Getter/Setter für die neuen Felder ---
    public function getAct(): ?string { return $this->act; }
    public function setAct(?string $act): self { $this->act = $act; return $this; }

    public function getFirstname(): ?string { return $this->firstname; }
    public function setFirstname(?string $firstname): self { $this->firstname = $firstname; return $this; }

    public function getLastname(): ?string { return $this->lastname; }
    public function setLastname(?string $lastname): self { $this->lastname = $lastname; return $this; }
    // ------------------------------------------

    public function getOrigin(): ?string { return $this->origin; }
    public function setOrigin(string $origin): self { $this->origin = $origin; return $this; }

    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $externalId): self { $this->externalId = $externalId; return $this; }

    public function getRoles(): array { 
        $roles = $this->roles; 
        $roles[] = 'ROLE_USER'; 
        return array_unique($roles); 
    }
    public function setRoles(array $roles): self { $this->roles = $roles; return $this; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): self { $this->password = $password; return $this; }

    public function eraseCredentials(): void {}
    
    // Wichtig für Twig
    public function __toString(): string { 
        // Falls Vor/Nachname da ist, nutzen wir den, sonst Username
        if ($this->firstname && $this->lastname) {
            return $this->firstname . ' ' . $this->lastname;
        }
        return $this->username ?? ''; 
    }
}