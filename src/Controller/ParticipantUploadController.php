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
                $groupCache = []; 

                try {
                    while (($row = fgetcsv($handle, 1000, $separator)) !== false) {
                        $lineNumber++;

                        $row = array_map(function($cell) {
                            return mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
                        }, $row);

                        if (count($row) < 1 || (count($row) === 1 && trim($row[0]) === '')) {
                            continue;
                        }

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

                        // --- 2. User finden oder erstellen ---
                        $user = $userRepo->findOneBy(['importId' => $importIdRaw]);

                        if (!$user && $strategy === 'iserv_match') {
                            $user = $userRepo->findOneBy(['act' => $importIdRaw]); // Oder 'username'
                            if ($user) {
                                $user->setImportId($importIdRaw);
                            }
                        }

                        if (!$user) {
                            $user = new User();
                            // WICHTIG: User der Institution zuweisen!
                            $user->setInstitution($institution); 
                            
                            $user->setImportId($importIdRaw);
                            $user->setFirstname($firstname);
                            $user->setLastname($lastname);
                            
                            $genUsername = strtolower($firstname . '.' . $lastname . '.' . substr(md5($importIdRaw), 0, 4));
                            $genUsername = preg_replace('/[^a-z0-9.]/', '', $genUsername);
                            $user->setUsername($genUsername);
                            
                            $user->setPassword($passwordHasher->hashPassword($user, 'start123')); 
                            $user->setSource('csv');
                            $user->setRoles([]);
                            
                            $em->persist($user);
                        } else {
                            $user->setFirstname($firstname);
                            $user->setLastname($lastname);
                            // Falls User existiert aber keine Schule hat:
                            if (!$user->getInstitution()) {
                                $user->setInstitution($institution);
                            }
                        }

                        // --- 3. Gruppe (Klasse) verarbeiten ---
                        if ($groupName !== '') {
                            if (!isset($groupCache[$groupName])) {
                                // WICHTIG: Suche Gruppe NUR innerhalb dieser Schule!
                                $group = $groupRepo->findOneBy([
                                    'name' => $groupName,
                                    'institution' => $institution // <--- DAS HAT GEFEHLT
                                ]);
                                
                                if (!$group) {
                                    $group = new Group();
                                    $group->setName($groupName);
                                    // WICHTIG: Hier muss die Institution gesetzt werden (Pflichtfeld!)
                                    $group->setInstitution($institution); // <--- HIER WAR DER FEHLER
                                    $em->persist($group);
                                }
                                $groupCache[$groupName] = $group;
                            }
                            
                            $group = $groupCache[$groupName];
                            $user->addGroup($group);
                        }

                        // --- 4. Participant (Sportdaten) ---
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

                        $participant = $user->getParticipant();

                        if (!$participant) {
                            $participant = new Participant();
                            $participant->setUser($user);
                            $participant->setInstitution($institution); // Hier war es schon korrekt
                            $user->setParticipant($participant);
                        }

                        $participant->setGender($geschlecht);
                        $participant->setBirthdate($geburtsdatum);
                        $participant->setUsername($firstname . ' ' . $lastname);
                        $participant->setGroupName($groupName);
                        $participant->setUpdatedAt(new \DateTime());

                        $em->persist($participant);

                        if ($imported % 50 === 0) {
                            $em->flush();
                        }

                        $imported++;
                    }
                    
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