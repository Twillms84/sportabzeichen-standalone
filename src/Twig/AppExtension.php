<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // Registriert die Funktion "icon" für alle Twig-Dateien
            new TwigFunction('icon', [$this, 'renderIcon'], ['is_safe' => ['html']]),
        ];
    }

    public function renderIcon(string $name): string
    {
        // Hier bauen wir das HTML, das IServ früher erzeugt hat.
        // Meistens war das ein span mit entsprechenden Klassen.
        // Du kannst das später anpassen (z.B. für FontAwesome oder Bootstrap Icons).
        return sprintf('<span class="icon icon-%s"></span>', $name);
    }
}