<?php

namespace App\Entity;

use App\Repository\GroupRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupRepository::class)]
// WICHTIG: Wir nutzen Backticks `groups`, damit SQL nicht meckert, 
// aber der Name MUSS "groups" sein, damit dein alter Code die Tabelle findet.
#[ORM\Table(name: '`groups`')] 
class Group
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    // UMBENANNT: Von 'account' zu 'act'.
    // Der alte Code sucht nach 'act' (IServ Gruppen-ID).
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $act = null;

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

    public function getAct(): ?string
    {
        return $this->act;
    }

    public function setAct(?string $act): static
    {
        $this->act = $act;

        return $this;
    }
}