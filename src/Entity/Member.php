<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Diese Entity stellt die Verbindung zwischen Usern und Gruppen her.
 * Sie wird vom alten Code zwingend benötigt ("members" Tabelle).
 */
#[ORM\Table(name: 'members')]
#[ORM\Entity]
class Member
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ID der Gruppe (z.B. 'teachers') - verweist auf Group.act
    #[ORM\Column(length: 255)]
    private ?string $act = null;

    // ID des Users (z.B. '1') - verweist auf User.act
    #[ORM\Column(length: 255)]
    private ?string $actuser = null;

    public function getId(): ?int { return $this->id; }

    public function getAct(): ?string { return $this->act; }
    public function setAct(string $act): self { $this->act = $act; return $this; }

    public function getActuser(): ?string { return $this->actuser; }
    public function setActuser(string $actuser): self { $this->actuser = $actuser; return $this; }
}