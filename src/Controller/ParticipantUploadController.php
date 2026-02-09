<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Group;
use App\Entity\Participant;
use App\Repository\UserRepository;
use App\Repository\GroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/admin', name: 'admin_')]
final class ParticipantUploadController extends AbstractController
{
    #[Route('/participants_upload', name: 'participants_upload')]
    public function upload(
        Request $request, 
        EntityManagerInterface $em, 
        UserRepository $userRepo,
        GroupRepository $groupRepo,
        UserPasswordHasherInterface $passwordHasher
    ): Response
    {
        $imported = 0;
        $skipped  = 0;
        $error    = null;
        $message  = null;
        $detailedErrors = [];

        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');
            $strategy = $request->request->get('strategy', 'iserv_match');

            // --- 1. Vorbereitungs-Check: Institution ---
            // Wir brauchen die Institution als Objekt, nicht nur als ID
            $admin = $this->getUser();
            $institution = null;
            
            if ($admin && method_exists($admin, 'getInstitution')) {
                $institution = $admin->getInstitution();
            }

            if (!$institution) {
                $error = 'Fehler: Du bist keiner Institution zugewiesen. Import abgebrochen.';
            } elseif (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $error = 'Bitte eine gültige CSV-Datei auswählen.';
            } else {
                
                $filePath = $file->getRealPath();
                $firstLine = fgets(fopen($filePath, 'r'));
                $separator = str_contains($firstLine, ';') ? ';' : ',';
                
                $handle = fopen($filePath, 'r');
                fgetcsv($handle, 0, $separator); // Header überspringen
                
                $lineNumber = 1;

                // Cache für Gruppen-Objekte um DB-Abfragen zu sparen
                // Key = Name, Value = Group Entity
                $groupCache = []; 

                try {
                    while (($row = fgetcsv($handle, 1000, $separator)) !== false) {
                        $lineNumber++;

                        // Zeichensatz korrigieren
                        $row = array_map(function($cell) {
                            return mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
                        }, $row);

                        // Leere Zeilen skippen
                        if (count($row) < 1 || (count($row) === 1 && trim($row[0]) === '')) {
                            continue;
                        }

                        // Validierung Spaltenanzahl
                        if (count($row) < 5) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Zu wenig Spalten.";
                            continue;
                        }

                        $importIdRaw   = trim($row[0]); 
                        $geschlechtRaw = trim($row[1]); 
                        $geburtsdatumRaw = trim($row[2]);
                        $lastname      = trim($row[3]); 
                        $firstname     = trim($row[4] ?? '');
                        $groupName     = trim($row[5] ?? '');

                        if ($importIdRaw === '') continue;

                        // --- 2. User finden oder erstellen (ORM) ---
                        $user = $userRepo->findOneBy(['importId' => $importIdRaw]);

                        // Strategie: IServ Match über 'act' (Username)
                        if (!$user && $strategy === 'iserv_match') {
                            // Suche nach Username/Act
                            $user = $userRepo->findOneBy(['act' => $importIdRaw]); // Annahme: 'act' Feld existiert in Entity
                            
                            // Fallback: Wenn 'act' nicht im Entity gemappt ist, versuchen wir 'username'
                            if (!$user) {
                                // Hier musst du prüfen, wie dein Login-Feld heißt (meist 'username' oder 'email')
                                // $user = $userRepo->findOneBy(['username' => $importIdRaw]); 
                            }

                            if ($user) {
                                // Gefunden -> Import ID nachtragen
                                $user->setImportId($importIdRaw);
                            }
                        }

                        // Neu anlegen, wenn nicht gefunden
                        if (!$user) {
                            $user = new User();
                            $user->setImportId($importIdRaw);
                            $user->setFirstname($firstname);
                            $user->setLastname($lastname);
                            
                            // Username generieren
                            $genUsername = strtolower($firstname . '.' . $lastname . '.' . substr(md5($importIdRaw), 0, 4));
                            $genUsername = preg_replace('/[^a-z0-9.]/', '', $genUsername);
                            $user->setUsername($genUsername);
                            
                            // Dummy Passwort (oder leer lassen wenn erlaubt)
                            $user->setPassword($passwordHasher->hashPassword($user, 'start123')); 
                            
                            $user->setSource('csv');
                            $user->setRoles([]);
                            
                            $em->persist($user);
                        } else {
                            // Update User Info
                            $user->setFirstname($firstname);
                            $user->setLastname($lastname);
                        }

                        // --- 3. Gruppe (Klasse) verarbeiten ---
                        if ($groupName !== '') {
                            if (!isset($groupCache[$groupName])) {
                                // Versuche Gruppe in DB zu finden
                                $group = $groupRepo->findOneBy(['name' => $groupName]);
                                
                                if (!$group) {
                                    $group = new Group();
                                    $group->setName($groupName);
                                    // Optional: Gruppe der Institution zuweisen, falls Group Entity das unterstützt
                                    // if (method_exists($group, 'setInstitution')) { $group->setInstitution($institution); }
                                    $em->persist($group);
                                }
                                $groupCache[$groupName] = $group;
                            }
                            
                            $group = $groupCache[$groupName];
                            
                            // User zur Gruppe hinzufügen (Many-to-Many Handling in Doctrine)
                            // Methode addGroup() in User Entity prüft meistens schon auf Duplikate
                            $user->addGroup($group);
                        }

                        // --- 4. Participant (Sportdaten) ---
                        
                        // Validierung
                        $geschlecht = match (strtolower($geschlechtRaw)) {
                            'm', 'male', 'männlich' => 'MALE',
                            'w', 'f', 'female', 'weiblich' => 'FEMALE',
                            'd', 'diverse', 'divers' => 'DIVERSE',
                            default => null, 
                        };

                        $geburtsdatum = self::parseDate($geburtsdatumRaw);

                        if (!$geschlecht || !$geburtsdatum) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber ($lastname): Ungültiges Datum/Geschlecht.";
                            continue;
                        }

                        // Participant laden oder erstellen
                        $participant = $user->getParticipant();

                        if (!$participant) {
                            $participant = new Participant();
                            $participant->setUser($user);
                            // WICHTIG: Hier setzen wir die Institution via Objekt!
                            $participant->setInstitution($institution);
                            
                            // Beziehung auch andersrum setzen, damit Doctrine es sofort weiß
                            $user->setParticipant($participant);
                        }

                        // Daten setzen
                        $participant->setGender($geschlecht);
                        $participant->setGeburtsdatum($geburtsdatum);
                        $participant->setUsername($firstname . ' ' . $lastname); // Legacy Feld
                        $participant->setGroupName($groupName); // Legacy Feld
                        $participant->setUpdatedAt(new \DateTime());

                        // Damit wird es für das INSERT/UPDATE vorgemerkt
                        $em->persist($participant);

                        // Batch-Processing: Alle 50 Zeilen in die DB schreiben um Speicher zu sparen
                        if ($imported % 50 === 0) {
                            $em->flush();
                        }

                        $imported++;
                    }
                    
                    // Den Rest schreiben
                    $em->flush();

                } catch (\Exception $e) {
                    $error = 'Systemfehler beim Import: ' . $e->getMessage();
                }

                fclose($handle);
                
                if ($imported > 0) {
                    $message = "Import erfolgreich: $imported User verarbeitet.";
                } elseif ($skipped > 0 && !$error) {
                    $error = "Import abgeschlossen, aber $skipped Zeilen übersprungen.";
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
    
    private static function parseDate(?string $input): ?\DateTime
    {
        if (!$input) return null;
        $formats = ['d.m.Y', 'Y-m-d', 'd-m-Y', 'd/m/Y', 'j.n.Y'];
        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $input);
            if ($dt !== false) {
                 $dt->setTime(0, 0, 0);
                 $errors = \DateTime::getLastErrors();
                 if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) continue;
                 return $dt; // Doctrine braucht ein DateTime Objekt, keinen String!
            }
        }
        return null;
    }
}