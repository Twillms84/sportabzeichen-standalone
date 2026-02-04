<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Discipline;
use App\Entity\Requirement;
use App\Repository\DisciplineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class RequirementsImportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DisciplineRepository $disciplineRepo
    ) {
    }

    public function import(UploadedFile $file, int $year): array
    {
        $handle = fopen($file->getPathname(), 'r');
        if (!$handle) {
            throw new \RuntimeException('Datei konnte nicht geöffnet werden.');
        }

        $stats = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'disciplines_created' => 0
        ];

        // Header einlesen (optional: Spalten prüfen)
        $header = fgetcsv($handle, 0, ';'); 

        // Cache für Disziplinen, um DB-Abfragen zu sparen
        $disciplineCache = [];

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            // Erwarte Format: 
            // 0: ID/LfdNr, 1: Disziplin, 2: Kategorie, 3: Geschlecht (m/w), 
            // 4: AlterMin, 5: AlterMax, 6: Bronze, 7: Silber, 8: Gold
            
            // Mindestens 6 Spalten nötig
            if (count($data) < 6) {
                $stats['skipped']++;
                continue;
            }

            $discName = trim($data[1]); // z.B. "3000m Lauf"
            $category = trim($data[2]); // z.B. "Ausdauer"
            
            // Disziplin finden oder erstellen
            if (!isset($disciplineCache[$discName])) {
                $discipline = $this->disciplineRepo->findOneByName($discName);
                if (!$discipline) {
                    $discipline = new Discipline();
                    $discipline->setName($discName);
                    $discipline->setCategory($category);
                    $discipline->setUnit($this->guessUnit($discName)); // Automatische Einheit
                    $this->em->persist($discipline);
                    $stats['disciplines_created']++;
                }
                $disciplineCache[$discName] = $discipline;
            }
            $discipline = $disciplineCache[$discName];

            // Daten mappen
            $genderChar = strtolower(trim($data[3])); 
            $gender = ($genderChar === 'w' || $genderChar === 'f') ? 'FEMALE' : 'MALE';
            
            $ageMin = (int)$data[4];
            $ageMax = (int)$data[5];

            // Werte bereinigen (Komma zu Punkt)
            $bronze = $this->parseValue($data[6] ?? null);
            $silver = $this->parseValue($data[7] ?? null);
            $gold   = $this->parseValue($data[8] ?? null);

            // Anforderung erstellen
            $requirement = new Requirement();
            $requirement->setDiscipline($discipline);
            $requirement->setYear($year);
            $requirement->setGender($gender);
            $requirement->setMinAge($ageMin);
            $requirement->setMaxAge($ageMax);
            $requirement->setBronze($bronze);
            $requirement->setSilver($silver);
            $requirement->setGold($gold);
            
            // ID aus CSV (optional, falls vorhanden in Spalte 0)
            if (is_numeric($data[0])) {
                $requirement->setSelectionId((int)$data[0]);
            } else {
                $requirement->setSelectionId(0);
            }

            $this->em->persist($requirement);
            $stats['imported']++;
        }

        fclose($handle);
        $this->em->flush();

        return $stats;
    }

    /**
     * Wandelt Komma-Werte in Floats um und entfernt Tausendertrennzeichen
     */
    private function parseValue(?string $val): ?float
    {
        if (!$val || trim($val) === '') {
            return null;
        }
        // Entferne Tausenderpunkte (deutsch)
        $val = str_replace('.', '', $val);
        // Ersetze Dezimalkomma durch Punkt
        $val = str_replace(',', '.', $val);
        
        return (float)$val;
    }

    /**
     * Versucht anhand des Namens die Einheit zu raten
     */
    private function guessUnit(string $name): string
    {
        $lower = strtolower($name);
        if (str_contains($lower, 'lauf') || str_contains($lower, 'sprint') || str_contains($lower, 'walking') || str_contains($lower, 'schwimmen')) {
            return 'min:sec'; // Zeitbasiert
        }
        if (str_contains($lower, 'wurf') || str_contains($lower, 'stoß') || str_contains($lower, 'sprung')) {
            return 'm'; // Meterbasiert
        }
        if (str_contains($lower, 'seilspringen')) {
            return 'anzahl';
        }
        return '';
    }
}