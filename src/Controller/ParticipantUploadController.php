<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
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
            // Strategie: 'iserv_match' (suche nach Login/Act) oder 'standalone' (lege User an)
            $strategy = $request->request->get('strategy', 'iserv_match');

            if (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $error = 'Bitte eine gültige CSV-Datei auswählen.';
            } else {
                $filePath = $file->getRealPath();
                
                // 1. Trennzeichen erkennen
                $firstLine = fgets(fopen($filePath, 'r'));
                $separator = str_contains($firstLine, ';') ? ';' : ',';
                
                $handle = fopen($filePath, 'r');
                
                // Header überspringen
                fgetcsv($handle, 0, $separator); 
                $lineNumber = 1;

                // Caches für Performance (damit wir nicht für jede Zeile die Gruppe neu suchen)
                $groupCache = []; // ['5b' => 12, '6a' => 14]
                
                // Transaktion starten für Datenintegrität
                $conn->beginTransaction();

                try {
                    while (($row = fgetcsv($handle, 1000, $separator)) !== false) {
                        $row = array_map(function($cell) {
                            return mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
                        }, $row);
                        
                        $lineNumber++;

                        // Leere Zeilen ignorieren
                        if (count($row) < 1 || (count($row) === 1 && trim($row[0]) === '')) {
                            continue;
                        }

                        // Mapping (Spaltenindexe an deine CSV anpassen)
                        // 0: ID (Import ID)
                        // 2: Geschlecht
                        // 3: Datum
                        // 4: Nachname
                        // 5: Vorname
                        // 6: Klasse (Optional)

                        if (count($row) < 5) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Zu wenig Spalten.";
                            continue;
                        }

                        $importIdRaw   = trim($row[0]); 
                        $geschlechtRaw = trim($row[2]); 
                        $geburtsdatumRaw = trim($row[3]);
                        $lastname      = trim($row[4]); 
                        $firstname     = trim($row[5] ?? '');
                        $groupName     = trim($row[6] ?? '');

                        if ($importIdRaw === '') continue;

                        // --- 1. User finden oder erstellen ---
                        $userId = null;

                        if ($strategy === 'standalone') {
                            // User suchen oder anlegen anhand import_id
                            $userId = $conn->fetchOne("SELECT id FROM users WHERE import_id = ?", [$importIdRaw]);
                            
                            if (!$userId) {
                                // Neuen User anlegen
                                $conn->insert('users', [
                                    'import_id' => $importIdRaw,
                                    'firstname' => $firstname,
                                    'lastname'  => $lastname,
                                    'username'  => $firstname . ' ' . $lastname . '_' . substr($importIdRaw, -4), // Unique machen
                                    'source'    => 'csv',
                                    'roles'     => json_encode([]), // Serialisiertes leeres Array
                                ]);
                                $userId = $conn->lastInsertId();
                            }
                        } else {
                            // IServ Match Strategie: Wir suchen nach dem 'act' (Account Name) oder existierender import_id
                            // Annahme: In der CSV Spalte 0 steht der IServ-Login ODER eine ID, die wir zuordnen wollen.
                            
                            // A) Suche nach Import ID
                            $userId = $conn->fetchOne("SELECT id FROM users WHERE import_id = ?", [$importIdRaw]);

                            // B) Wenn nicht gefunden, Suche nach 'act' (Login Name)
                            if (!$userId) {
                                $userId = $conn->fetchOne("SELECT id FROM users WHERE LOWER(act) = LOWER(?)", [$importIdRaw]);
                                
                                // Wenn gefunden, Import ID speichern für Zukunft
                                if ($userId) {
                                    $conn->update('users', ['import_id' => $importIdRaw], ['id' => $userId]);
                                }
                            }
                        }

                        if (!$userId) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber ($importIdRaw): User in Datenbank nicht gefunden (IServ-Modus).";
                            continue;
                        }

                        // --- 2. Gruppe verarbeiten (Klasse) ---
                        if ($groupName !== '') {
                            // Check Cache
                            if (!isset($groupCache[$groupName])) {
                                // Check DB
                                $groupId = $conn->fetchOne('SELECT id FROM "groups" WHERE name = ?', [$groupName]);
                                if (!$groupId) {
                                    // Create Group
                                    $conn->insert('"groups"', ['name' => $groupName]);
                                    $groupId = $conn->lastInsertId();
                                }
                                $groupCache[$groupName] = $groupId;
                            }
                            $groupId = $groupCache[$groupName];

                            // Link User <-> Group (users_groups)
                            // "ON CONFLICT DO NOTHING" Ersatz für Postgres/MySQL ohne Exception
                            $exists = $conn->fetchOne('SELECT 1 FROM users_groups WHERE user_id = ? AND group_id = ?', [$userId, $groupId]);
                            if (!$exists) {
                                $conn->insert('users_groups', ['user_id' => $userId, 'group_id' => $groupId]);
                            }
                        }

                        // --- 3. Participant (Sportdaten) verarbeiten ---
                        
                        // Validierung
                        $geschlecht = match (strtolower($geschlechtRaw)) {
                            'm', 'male', 'männlich' => 'MALE',
                            'w', 'f', 'female', 'weiblich' => 'FEMALE',
                            'd', 'diverse', 'divers' => 'DIVERSE',
                            default => null, 
                        };

                        $geburtsdatum = self::parseDate($geburtsdatumRaw);

                        if (!$geschlecht || !$geburtsdatum) {
                             $detailedErrors[] = "Zeile $lineNumber: Ungültiges Datum ($geburtsdatumRaw) oder Geschlecht ($geschlechtRaw)";
                             // Wir brechen hier nicht ab, sondern updaten nur den Rest nicht, 
                             // oder wir überspringen. Hier: Überspringen um Datenmüll zu vermeiden.
                             $skipped++;
                             continue;
                        }

                        // Prüfen ob Participant Eintrag existiert
                        $existingPartId = $conn->fetchOne(
                            'SELECT id FROM sportabzeichen_participants WHERE user_id = ?',
                            [$userId]
                        );

                        $data = [
                            'user_id'      => $userId,
                            'geschlecht'   => $geschlecht,
                            'geburtsdatum' => $geburtsdatum,
                            'updated_at'   => (new \DateTime())->format('Y-m-d H:i:s'),
                            // Legacy Felder füllen wir auch, falls alte Logik noch greift
                            'username'     => $firstname . ' ' . $lastname, 
                            'group_name'   => $groupName 
                        ];

                        if ($existingPartId) {
                            $conn->update('sportabzeichen_participants', $data, ['id' => $existingPartId]);
                        } else {
                            $conn->insert('sportabzeichen_participants', $data);
                        }

                        $imported++;
                    }
                    
                    $conn->commit();
                    
                } catch (\Throwable $e) {
                    $conn->rollBack();
                    $error = 'Systemfehler beim Import: ' . $e->getMessage();
                }

                fclose($handle);
                
                if ($imported > 0) {
                    $message = "Import erfolgreich: $imported User aktualisiert/angelegt.";
                } elseif ($skipped > 0 && !$error) {
                    $error = "Import abgeschlossen mit Warnungen ($skipped übersprungen).";
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
                 $dt->setTime(0, 0, 0);
                 $errors = \DateTime::getLastErrors();
                 if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) continue;
                 return $dt->format('Y-m-d');
            }
        }
        return null;
    }
}