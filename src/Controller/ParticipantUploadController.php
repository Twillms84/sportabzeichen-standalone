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
        UserPasswordHasherInterface $passwordHasher,
        \Psr\Log\LoggerInterface $logger // Logger hinzugefügt
    ): Response {
        $imported = 0;
        $updated  = 0; // Zähler für Updates
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
                $error = 'Keine Institution gefunden.';
            } elseif (!$file) {
                $error = 'Keine Datei empfangen.';
            } else {
                $handle = fopen($file->getRealPath(), 'r');
                $separator = str_contains(fgets($handle), ';') ? ';' : ',';
                rewind($handle);
                fgetcsv($handle, 0, $separator); // Skip Header

                // Caching wie gehabt
                $existingUsers = $userRepo->findBy(['institution' => $institution]);
                $userCache = [];
                foreach ($existingUsers as $u) {
                    if ($u->getImportId()) $userCache[trim((string)$u->getImportId())] = $u;
                }

                $lineNumber = 1;
                try {
                    while (($row = fgetcsv($handle, 1000, $separator)) !== false) {
                        $lineNumber++;
                        $row = array_map(fn($c) => mb_convert_encoding($c, 'UTF-8', 'Windows-1252'), $row);
                        
                        if (count($row) < 5) {
                            $detailedErrors[] = "Zeile $lineNumber: Zu wenig Spalten (" . count($row) . ")";
                            continue;
                        }

                        $importIdRaw = trim((string)$row[0]);
                        $lastname    = trim($row[3]);
                        $firstname   = trim($row[4]);

                        // DEBUG LOG
                        $logger->info("Verarbeite Zeile $lineNumber: ID $importIdRaw - $firstname $lastname");

                        // 1. Suche Match
                        $user = $userCache[$importIdRaw] ?? null;
                        $isNew = false;

                        if (!$user) {
                            $logger->debug("-> User nicht im Cache. Erstelle neu.");
                            $user = new User();
                            $user->setInstitution($institution);
                            $user->setImportId($importIdRaw);
                            $user->setSource('csv');
                            $user->setUsername('u' . $importIdRaw . uniqid()); // Provisorisch
                            $user->setPassword('dummy'); 
                            $em->persist($user);
                            $isNew = true;
                            $imported++;
                        } else {
                            $logger->debug("-> User gefunden (ID: {$user->getId()}). Update gestartet.");
                            $updated++;
                        }

                        // 2. Daten setzen
                        $user->setFirstname($firstname);
                        $user->setLastname($lastname);

                        // 3. Participant Logik
                        $geburtsdatum = self::parseDate($row[2]);
                        if (!$geburtsdatum) {
                            $detailedErrors[] = "Zeile $lineNumber ($firstname $lastname): Datum fehlerhaft: '{$row[2]}'";
                            $skipped++;
                            continue;
                        }

                        $participant = $user->getParticipant() ?? new Participant();
                        $participant->setUser($user);
                        $participant->setInstitution($institution);
                        $participant->setBirthdate($geburtsdatum);
                        $participant->setUpdatedAt(new \DateTime());
                        $em->persist($participant);

                        if ($lineNumber % 50 === 0) {
                            $em->flush();
                            $logger->notice("Batch Flush bei Zeile $lineNumber");
                        }
                    }
                    $em->flush();
                    $message = "Erfolg: $imported neu, $updated aktualisiert, $skipped übersprungen.";

                } catch (\Exception $e) {
                    $logger->error("Abbruch in Zeile $lineNumber: " . $e->getMessage());
                    $error = "Fehler in Zeile $lineNumber: " . $e->getMessage();
                }
                fclose($handle);
            }
        }

        return $this->render('admin/upload_participants.html.twig', [
            'activeTab' => 'participants_upload',
            'imported'  => $imported,
            'updated'   => $updated, // Im Twig ausgeben!
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