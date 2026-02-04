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
            // Strategie: 'iserv_match' (Versucht Zuordnung zu IServ) oder 'standalone' (Nur Import)
            $strategy = $request->request->get('strategy', 'iserv_match'); 

            if (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $error = 'Bitte eine gültige CSV-Datei auswählen.';
            } else {
                $handle = fopen($file->getRealPath(), 'r');
                
                // Header lesen
                $header = fgetcsv($handle, 1000, ';'); 
                
                // Prüfen ob wir genug Spalten für Standalone haben (ID;Geschlecht;Datum;Vorname;Nachname)
                // Wenn nicht, können wir nur IServ-Matching machen.
                $hasNames = count($header) >= 5;

                $lineNumber = 1;

                // Prepared Statements vorbereiten
                // 1. Suche in IServ Users (nur nötig wenn Strategy nicht rein standalone ist)
                $sqlLookup = "SELECT id, act, firstname, lastname FROM users WHERE import_id = :val AND deleted IS NULL LIMIT 1";
                $stmtLookup = $conn->prepare($sqlLookup);

                // 2. Suche existierenden Participant (über external_id ODER user_id)
                $stmtCheckExist = $conn->prepare('
                    SELECT id FROM sportabzeichen_participants 
                    WHERE (user_id = :uid AND :uid IS NOT NULL) 
                       OR (external_id = :ext_id AND :ext_id IS NOT NULL)
                ');

                while (($row = fgetcsv($handle, 1000, ';')) !== false) {
                    $lineNumber++;

                    // Fallback für Komma
                    if (count($row) < 2) {
                        $lineParsed = str_getcsv($row[0], ',');
                        if (count($lineParsed) >= 2) $row = $lineParsed;
                    }

                    try {
                        if (count($row) < 3) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Zu wenige Spalten.";
                            continue;
                        }

                        // CSV Parsing
                        $identifier      = trim($row[0]); // ID (Import-ID oder Externe ID)
                        $geschlechtRaw   = trim($row[1]);
                        $geburtsdatumRaw = trim($row[2]);
                        
                        // Namen optional aus CSV lesen
                        $csvFirstname = isset($row[3]) ? trim($row[3]) : null;
                        $csvLastname  = isset($row[4]) ? trim($row[4]) : null;

                        if ($identifier === '') continue;

                        // -----------------------------------------------------------
                        // LOGIK: WER IST DAS?
                        // -----------------------------------------------------------
                        
                        $iservUser = null;
                        
                        // Versuch 1: Matching mit IServ Datenbank (wenn gewünscht)
                        if ($strategy !== 'standalone') {
                            $iservUser = $stmtLookup->executeQuery(['val' => $identifier])->fetchAssociative();
                        }

                        // Entscheidung treffen
                        $userId = null;
                        $username = null;
                        $firstname = $csvFirstname;
                        $lastname  = $csvLastname;
                        $origin    = 'CSV_STANDALONE';
                        $externalId = $identifier;

                        if ($iservUser) {
                            // TREFFER im IServ
                            $userId    = $iservUser['id'];
                            $username  = $iservUser['act'];
                            $firstname = $iservUser['firstname']; // IServ Namen haben Vorrang
                            $lastname  = $iservUser['lastname'];
                            $origin    = 'ISERV_IMPORT';
                            $externalId = null; // Wenn IServ User, brauchen wir oft keine ext_id, oder wir speichern die Import-ID trotzdem
                        } else {
                            // KEIN TREFFER -> Standalone Fall
                            if (!$hasNames || empty($firstname) || empty($lastname)) {
                                $skipped++;
                                $detailedErrors[] = "Zeile $lineNumber ($identifier): Kein IServ-Treffer und keine Namen in CSV.";
                                continue;
                            }
                            // Hier behalten wir die Werte aus der CSV
                            $username = $firstname . ' ' . $lastname; // Fallback Username
                        }

                        // -----------------------------------------------------------
                        // VALIDIERUNG
                        // -----------------------------------------------------------
                        $geschlecht = match (strtolower($geschlechtRaw)) {
                            'm', 'male', 'männlich' => 'MALE',
                            'w', 'f', 'female', 'weiblich' => 'FEMALE',
                            default => null, 
                        };

                        if (!$geschlecht) {
                            $skipped++; continue;
                        }

                        $geburtsdatum = self::parseDate($geburtsdatumRaw);
                        if (!$geburtsdatum) {
                            $skipped++; continue;
                        }

                        // -----------------------------------------------------------
                        // SPEICHERN (Upsert Logik)
                        // -----------------------------------------------------------
                        
                        // Existiert dieser Teilnehmer schon im Sportabzeichen-System?
                        $existingPartId = $stmtCheckExist->executeQuery([
                            'uid'    => $userId,
                            'ext_id' => $externalId
                        ])->fetchOne();

                        $data = [
                            'user_id'      => $userId,      // Kann NULL sein!
                            'external_id'  => $externalId,  // Kann NULL sein (wenn user_id gesetzt)
                            'origin'       => $origin,
                            'firstname'    => $firstname,
                            'lastname'     => $lastname,
                            'username'     => $username,
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
                        $detailedErrors[] = "Zeile $lineNumber: " . $e->getMessage();
                    }
                }
                fclose($handle);
                
                if ($imported > 0) {
                    $message = "Import: $imported verarbeitet ($skipped übersprungen).";
                } elseif ($skipped > 0) {
                    $error = "Fehler beim Import.";
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
    
    // ... parseDate Funktion bleibt gleich ...
    private static function parseDate(?string $input): ?string { /* ... */ return null; }
}