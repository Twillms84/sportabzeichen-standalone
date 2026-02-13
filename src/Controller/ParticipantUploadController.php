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
        \Psr\Log\LoggerInterface $logger
    ): Response {
        $imported = 0;
        $updated  = 0;
        $skipped  = 0;
        $identical = 0;
        $detailedErrors = [];

        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');
            $admin = $this->getUser();
            $institution = method_exists($admin, 'getInstitution') ? $admin->getInstitution() : null;

            if (!$institution || !$file) {
                $error = 'Fehler: Institution oder Datei fehlt.';
            } else {
                $filePath = $file->getRealPath();
                $content = file_get_contents($filePath);
                
                // --- 1. ENCODING FIX ---
                // Prüfen, ob Datei UTF-8 ist, sonst konvertieren
                if (!mb_check_encoding($content, 'UTF-8')) {
                    $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
                }
                
                $lines = explode("\n", str_replace("\r", "", $content));
                $firstLine = $lines[0] ?? '';
                $separator = str_contains($firstLine, ';') ? ';' : ',';

                // --- 2. CACHING ---
                $existingUsers = $userRepo->findBy(['institution' => $institution]);
                $userCache = [];
                foreach ($existingUsers as $u) {
                    if ($u->getImportId()) $userCache[trim((string)$u->getImportId())] = $u;
                }
                
                $existingGroups = $groupRepo->findBy(['institution' => $institution]);
                $groupCache = [];
                foreach ($existingGroups as $g) { $groupCache[$g->getName()] = $g; }

                // --- 3. PROCESSING ---
                foreach ($lines as $index => $line) {
                    if ($index === 0 || empty(trim($line))) continue; // Header & Leerzeilen skip
                    
                    $row = str_getcsv($line, $separator);
                    if (count($row) < 5) continue;

                    $importIdRaw = trim($row[0]);
                    $geschlechtRaw = trim($row[1]);
                    $geburtsdatumRaw = trim($row[2]);
                    $lastname = trim($row[3]);
                    $firstname = trim($row[4]);
                    $groupName = trim($row[5] ?? '');

                    // Falls Datum im Namen klebt (Fix für "Gröneweg-23.03.2013")
                    if (str_contains($lastname, '-') && empty($geburtsdatumRaw)) {
                        $parts = explode('-', $lastname);
                        $geburtsdatumRaw = array_pop($parts);
                        $lastname = implode('-', $parts);
                    }

                    $geburtsdatum = self::parseDate($geburtsdatumRaw);
                    $user = $userCache[$importIdRaw] ?? null;
                    $isNew = ($user === null);
                    $hasChanges = false;

                    if ($isNew) {
                        $user = new User();
                        $user->setInstitution($institution);
                        $user->setImportId($importIdRaw);
                        $user->setSource('csv');
                        $user->setPassword($passwordHasher->hashPassword($user, 'start123'));
                        $genUsername = strtolower($firstname . '.' . $lastname . '.' . substr(md5($importIdRaw), 0, 4));
                        $user->setUsername(preg_replace('/[^a-z0-9.]/', '', $genUsername));
                        $em->persist($user);
                        $hasChanges = true;
                    }

                    // Vergleich für Statistik
                    $participant = $user->getParticipant() ?? new Participant();
                    
                    if ($user->getFirstname() !== $firstname || 
                        $user->getLastname() !== $lastname ||
                        $participant->getGroupName() !== $groupName ||
                        ($participant->getBirthdate() ? $participant->getBirthdate()->format('Y-m-d') : null) !== ($geburtsdatum ? $geburtsdatum->format('Y-m-d') : null)
                    ) {
                        $hasChanges = true;
                    }

                    if ($hasChanges) {
                        $user->setFirstname($firstname);
                        $user->setLastname($lastname);

                        // Gruppe verarbeiten
                        if ($groupName !== '') {
                            if (!isset($groupCache[$groupName])) {
                                $group = new Group();
                                $group->setName($groupName);
                                $group->setInstitution($institution);
                                $em->persist($group);
                                $groupCache[$groupName] = $group;
                            }
                            $targetGroup = $groupCache[$groupName];
                            foreach ($user->getGroups() as $oldG) {
                                if ($oldG->getInstitution() === $institution) $user->removeGroup($oldG);
                            }
                            $user->addGroup($targetGroup);
                        }

                        $participant->setUser($user);
                        $participant->setInstitution($institution);
                        $participant->setBirthdate($geburtsdatum);
                        $participant->setGender(match(strtolower($geschlechtRaw)){'m','male'=>'MALE','w','f','female'=>'FEMALE',default=>'DIVERSE'});
                        $participant->setGroupName($groupName);
                        $participant->setUpdatedAt(new \DateTime());
                        $em->persist($participant);

                        $isNew ? $imported++ : $updated++;
                    } else {
                        $identical++;
                    }

                    if (($imported + $updated) % 100 === 0) $em->flush();
                }
                $em->flush();
                $message = "Ergebnis: $imported neu, $updated aktualisiert, $identical identisch.";
            }
        }

        return $this->render('admin/upload_participants.html.twig', [
            'activeTab' => 'participants_upload',
            'imported' => $imported,
            'updated' => $updated,
            'identical' => $identical,
            'error' => $error ?? null,
            'message' => $message ?? null,
            'detailedErrors' => $detailedErrors
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