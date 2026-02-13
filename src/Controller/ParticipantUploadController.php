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
    ): Response {
        $imported = 0;
        $skipped  = 0;
        $error    = null;
        $message  = null;
        $detailedErrors = [];

        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');
            $strategy = $request->request->get('strategy', 'iserv_match');

            $admin = $this->getUser();
            $institution = method_exists($admin, 'getInstitution') ? $admin->getInstitution() : null;

            if (!$institution) {
                $error = 'Fehler: Du bist keiner Institution zugewiesen.';
            } elseif (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $error = 'Bitte eine gültige CSV-Datei auswählen.';
            } else {
                $handle = fopen($file->getRealPath(), 'r');
                $firstLine = fgets($handle);
                $separator = str_contains($firstLine, ';') ? ';' : ',';
                rewind($handle);
                fgetcsv($handle, 0, $separator); // Header überspringen

                // --- OPTIMIERUNG: CACHING ---
                $existingUsers = $userRepo->findBy(['institution' => $institution]);
                $userCache = [];
                foreach ($existingUsers as $u) {
                    if ($u->getImportId()) $userCache[$u->getImportId()] = $u;
                    if ($u->getUsername()) $userCache['uname_'.$u->getUsername()] = $u;
                }

                $existingGroups = $groupRepo->findBy(['institution' => $institution]);
                $groupCache = [];
                foreach ($existingGroups as $g) {
                    $groupCache[$g->getName()] = $g;
                }

                $lineNumber = 1;
                try {
                    while (($row = fgetcsv($handle, 1000, $separator)) !== false) {
                        $lineNumber++;
                        $row = array_map(fn($c) => mb_convert_encoding($c, 'UTF-8', 'Windows-1252'), $row);

                        if (count($row) < 5 || trim($row[0]) === '') continue;

                        [$importIdRaw, $geschlechtRaw, $geburtsdatumRaw, $lastname, $firstname] = $row;
                        $groupName = trim($row[5] ?? '');
                        $importIdRaw = trim($importIdRaw);

                        // 1. User finden (Update-Check)
                        $user = $userCache[$importIdRaw] ?? null;

                        if (!$user && $strategy === 'iserv_match') {
                            $user = $userCache['uname_'.$importIdRaw] ?? null;
                            if ($user) {
                                $user->setImportId($importIdRaw);
                                $userCache[$importIdRaw] = $user;
                            }
                        }

                        if (!$user) {
                            // NEUANLAGE
                            $user = new User();
                            $user->setInstitution($institution);
                            $user->setImportId($importIdRaw);
                            $user->setSource('csv');
                            $user->setPassword($passwordHasher->hashPassword($user, 'start123'));
                            
                            $genUsername = strtolower($firstname . '.' . $lastname . '.' . substr(md5($importIdRaw), 0, 4));
                            $user->setUsername(preg_replace('/[^a-z0-9.]/', '', $genUsername));
                            $em->persist($user);
                            $userCache[$importIdRaw] = $user;
                        }

                        // UPDATE der Stammdaten
                        $user->setFirstname(trim($firstname));
                        $user->setLastname(trim($lastname));

                        // 2. Gruppe verarbeiten (mit Wechsel-Logik)
                        if ($groupName !== '') {
                            if (!isset($groupCache[$groupName])) {
                                $group = new Group();
                                $group->setName($groupName);
                                $group->setInstitution($institution);
                                $em->persist($group);
                                $groupCache[$groupName] = $group;
                            }
                            
                            $targetGroup = $groupCache[$groupName];
                            
                            // Alte Gruppen der gleichen Institution entfernen (Klassenwechsel)
                            foreach ($user->getGroups() as $oldGroup) {
                                if ($oldGroup->getInstitution() === $institution && $oldGroup !== $targetGroup) {
                                    $user->removeGroup($oldGroup);
                                }
                            }
                            $user->addGroup($targetGroup);
                        }

                        // 3. Participant (Sportdaten) aktualisieren
                        $geburtsdatum = self::parseDate($geburtsdatumRaw);
                        $geschlecht = match (strtolower(trim($geschlechtRaw))) {
                            'm', 'male', 'männlich' => 'MALE',
                            'w', 'f', 'female', 'weiblich' => 'FEMALE',
                            default => 'DIVERSE',
                        };

                        if (!$geburtsdatum) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Ungültiges Datum ($geburtsdatumRaw)";
                            continue;
                        }

                        $participant = $user->getParticipant() ?? new Participant();
                        if (!$participant->getUser()) {
                            $participant->setUser($user);
                            $participant->setInstitution($institution);
                            $user->setParticipant($participant);
                        }

                        $participant->setGender($geschlecht);
                        $participant->setBirthdate($geburtsdatum);
                        $participant->setUsername($user->getFirstname() . ' ' . $user->getLastname());
                        $participant->setGroupName($groupName); // Text-Feld für die Anzeige
                        $participant->setUpdatedAt(new \DateTime());
                        $em->persist($participant);

                        $imported++;

                        if ($imported % 100 === 0) {
                            $em->flush();
                        }
                    }
                    $em->flush();
                    $message = "Verarbeitung abgeschlossen: $imported Teilnehmer importiert/aktualisiert.";

                } catch (\Exception $e) {
                    $error = 'Systemfehler beim Import: ' . $e->getMessage();
                }
                fclose($handle);
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