<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType; // Wichtig für saubere Typen
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
final class ParticipantUploadController extends AbstractController
{
    #[Route('/participants_upload', name: 'participants_upload')]
    public function upload(Request $request, Connection $conn): Response
    {
        $imported = 0;
        $skipped  = 0;
        $error    = null;
        $message  = null;
        $detailedErrors = [];

        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');
            $strategy = $request->request->get('strategy', 'import_id');

            if (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $error = 'Bitte eine gültige CSV-Datei auswählen.';
            } else {
                $filePath = $file->getRealPath();
                
                // 1. Trennzeichen automatisch erkennen (wichtig!)
                $firstLine = fgets(fopen($filePath, 'r'));
                $separator = str_contains($firstLine, ';') ? ';' : ',';
                
                $handle = fopen($filePath, 'r');
                
                // Header lesen (wir gehen davon aus, dass Zeile 1 IMMER Überschriften sind)
                $header = fgetcsv($handle, 0, $separator); 
                $lineNumber = 1;

                // Prepared Statements
                // IServ Lookup (nur nötig, wenn NICHT Standalone)
                $sqlLookup = "SELECT id, act, firstname, lastname FROM users WHERE import_id = :val AND deleted IS NULL LIMIT 1";
                if ($strategy === 'act') {
                    $sqlLookup = "SELECT id, act, firstname, lastname FROM users WHERE LOWER(act) = LOWER(:val) AND deleted IS NULL LIMIT 1";
                }
                $stmtLookup = $conn->prepare($sqlLookup);

                // Existenz-Check
                $stmtCheckExist = $conn->prepare('
                    SELECT id FROM sportabzeichen_participants 
                    WHERE (user_id = :uid AND :uid IS NOT NULL) 
                    OR (external_id = :ext_id AND :ext_id IS NOT NULL)
                ');

                while (($row = fgetcsv($handle, 1000, $separator)) !== false) {
                    $row = array_map(function($cell) {
                        return mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
                    }, $row);
                    
                    $lineNumber++;

                    // Leere Zeilen ignorieren
                    if (count($row) < 1 || (count($row) === 1 && trim($row[0]) === '')) {
                        continue;
                    }

                    try {
                        // Mapping angepasst an deine Datei:
                        // 0: ID (1115860)
                        // 1: ID doppelt (1115860) -> ignorieren
                        // 2: Geschlecht (m)
                        // 3: Datum (06.01.2008)
                        // 4: Nachname (Penning)
                        // 5: Vorname (Raimo)
                        // 6: Klasse (8b) - falls vorhanden

                        if (count($row) < 3) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Zu wenig Spalten.";
                            continue;
                        }

                        $identifier      = trim($row[0]); 
                        $geschlechtRaw   = trim($row[1]); // Spalte 3 (Index 2)
                        $geburtsdatumRaw = trim($row[2]); // Spalte 4 (Index 3)
                        
                        // Namen & Gruppe (Achtung: Erst Nachname, dann Vorname in deiner CSV)
                        $csvLastname  = isset($row[3]) ? trim($row[3]) : null; 
                        $csvFirstname = isset($row[4]) ? trim($row[4]) : null;
                        $csvGroup     = isset($row[5]) ? trim($row[5]) : null;

                        if ($identifier === '') continue;

                        // --- LOGIK START ---

                        $userId = null;
                        $firstname = $csvFirstname;
                        $lastname  = $csvLastname;
                        $groupName = $csvGroup;
                        $origin    = 'CSV_STANDALONE';
                        $externalId = $identifier;
                        $username   = $csvFirstname . ' ' . $csvLastname; 

                        // Fall A: Standalone Modus
                        if ($strategy === 'standalone') {
                            if (empty($csvFirstname) || empty($csvLastname)) {
                                $skipped++;
                                $detailedErrors[] = "Zeile $lineNumber ($identifier): Standalone-Modus, aber Namen fehlen.";
                                continue;
                            }
                        } 
                        // Fall B: IServ Integration
                        else {
                            // Lookup ausführen
                            $iservUser = $stmtLookup->executeQuery(['val' => $identifier])->fetchAssociative();

                            if ($iservUser) {
                                $userId    = $iservUser['id'];
                                $username  = $iservUser['act'];
                                $firstname = $iservUser['firstname']; 
                                $lastname  = $iservUser['lastname'];
                                $origin    = 'ISERV_IMPORT';
                                $externalId = null; 
                            } else {
                                // Nicht im IServ -> Fallback auf CSV Namen
                                if (empty($csvFirstname) || empty($csvLastname)) {
                                    $skipped++;
                                    $detailedErrors[] = "Zeile $lineNumber ($identifier): Nicht im IServ gefunden und keine Namen in CSV.";
                                    continue;
                                }
                            }
                        }

                        // --- VALIDIERUNG ---

                        $geschlecht = match (strtolower($geschlechtRaw)) {
                            'm', 'male', 'männlich' => 'MALE',
                            'w', 'f', 'female', 'weiblich' => 'FEMALE',
                            default => null, 
                        };

                        if (!$geschlecht) {
                            $skipped++; 
                            $detailedErrors[] = "Zeile $lineNumber: Ungültiges Geschlecht '$geschlechtRaw'";
                            continue;
                        }

                        $geburtsdatum = self::parseDate($geburtsdatumRaw);
                        if (!$geburtsdatum) {
                            $skipped++; 
                            $detailedErrors[] = "Zeile $lineNumber: Ungültiges Datum '$geburtsdatumRaw'";
                            continue;
                        }

                        // --- DB WRITE (KORRIGIERT) ---
                        
                        // Wir prüfen direkt, ohne Prepared Statement Objekt, um den Fehler zu vermeiden
                        $existingPartId = $conn->fetchOne(
                            'SELECT id FROM sportabzeichen_participants WHERE user_id = :uid OR external_id = :ext_id',
                            ['uid' => $userId, 'ext_id' => $externalId]
                        );

                        $data = [
                            'user_id'      => $userId,
                            'external_id'  => $externalId,
                            'origin'       => $origin,
                            'firstname'    => $firstname,
                            'lastname'     => $lastname,
                            'username'     => $username,
                            'group_name'   => $groupName,
                            'geschlecht'   => $geschlecht,
                            'geburtsdatum' => $geburtsdatum,
                            'updated_at'   => (new \DateTime())->format('Y-m-d H:i:s')
                        ];

                        if ($existingPartId) {
                            $conn->update('sportabzeichen_participants', $data, ['id' => $existingPartId]);
                        } else {
                            $conn->insert('sportabzeichen_participants', $data);
                        }

                        $imported++;

                    } catch (\Throwable $e) {
                        $skipped++;
                        $detailedErrors[] = "Zeile $lineNumber: Systemfehler: " . $e->getMessage();
                    }
                }
                fclose($handle);
                
                if ($imported > 0) {
                    $message = "Import: $imported erfolgreich, $skipped übersprungen.";
                } elseif ($skipped > 0) {
                    $error = "Import fehlgeschlagen ($skipped Zeilen übersprungen). Bitte Fehlerliste prüfen.";
                }
            }
        }

        return $this->render('admin/upload_participants.html.twig', [
            'activeTab' => 'participants_upload',
            'imported'  => $imported,
            'skipped'   => $skipped,
            'error'     => $error,
            'message'   => $message,
            'detailedErrors' => $detailedErrors,
        ]);
    }
    
    private static function parseDate(?string $input): ?string
    {
        if (!$input) return null;
        
        $formats = ['d.m.Y', 'Y-m-d', 'd-m-Y', 'd/m/Y', 'j.n.Y'];
        
        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $input);
            if ($dt !== false) {
                 // Reset auf 00:00:00 um keine Zeitverschiebungsprobleme zu haben
                 $dt->setTime(0, 0, 0);
                 // Wichtig: Checken ob das Parsing logisch war (z.B. verhindert 32.01.2023)
                 $errors = \DateTime::getLastErrors();
                 if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                     continue;
                 }
                 return $dt->format('Y-m-d');
            }
        }
        return null;
    }
}