<?php

declare(strict_types=1);

namespace App\Controller\Admin; // Neuer Namespace für Admin-Subordner

use App\Entity\Participant;
use App\Entity\User;
use App\Entity\Group;
use App\Entity\Exam; 
use App\Entity\ExamParticipant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

// Basis-Route für alle Methoden in dieser Klasse
// Der Name-Prefix sorgt dafür, dass die Routen weiterhin 'admin_participants_index' heißen
#[Route('/admin/participants', name: 'admin_participants_')]
final class ParticipantController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    /**
     * Helper: Holt Institution oder wirft Error (spart Code-Duplizierung)
     */
    private function getInstitutionOrDeny()
    {
        $user = $this->getUser();
        $institution = $user ? $user->getInstitution() : null;

        if (!$institution) {
            throw new AccessDeniedException('Keine Institution zugewiesen.');
        }
        return $institution;
    }

    #[Route('/', name: 'index')]
    public function index(Request $request): Response
    {
        $institution = $this->getInstitutionOrDeny();

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;
        $q = trim((string)$request->query->get('q'));
        $filter = $request->query->get('filter'); // Neu für den "Ohne Gruppe" Filter

        $qb = $this->em->getRepository(Participant::class)->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')
            ->addSelect('u')
            ->leftJoin('u.groups', 'g')
            ->addSelect('g')
            ->where('p.institution = :institution')
            ->setParameter('institution', $institution)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC');

        // NEU: Filter für Teilnehmer ohne Gruppe
       if ($filter === 'no_group') {
            $qb->andWhere('g.id IS NULL');
        } elseif ($filter && str_starts_with($filter, 'group_')) {
            // Extrahiere die ID (aus "group_4" wird 4)
            $groupId = (int) str_replace('group_', '', $filter);
            
            $qb->andWhere('g.id = :groupId')
            ->setParameter('groupId', $groupId);
        }

        if ($q !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'u.lastname LIKE :search',
                    'u.firstname LIKE :search',
                    'u.act LIKE :search',
                    'u.username LIKE :search',
                    'u.importId LIKE :search'
                )
            )
            ->setParameter('search', '%' . $q . '%');
        }

        $query = $qb->getQuery();
        $query->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);

        $paginator = new Paginator($query, fetchJoinCollection: true);
        $totalCount = count($paginator);
        $maxPages = max(1, (int) ceil($totalCount / $limit));

        // NEU: Gruppen für das Bulk-Dropdown laden
        $availableGroups = $this->em->getRepository(Group::class)->findBy(
            ['institution' => $institution],
            ['name' => 'ASC']
        );

        return $this->render('admin/participants/index.html.twig', [
            'participants'    => $paginator,
            'availableGroups' => $availableGroups, // Wichtig für das Template!
            'activeTab'       => 'participants_manage',
            'currentPage'     => $page,
            'maxPages'        => $maxPages,
            'totalCount'      => $totalCount,
            'searchTerm'      => $q,
            'searchQuery'     => $q, 
            'pagesCount'      => $maxPages 
        ]);
    }

    #[Route('/missing', name: 'missing')] // Ergibt: admin_participants_missing
    public function missing(Request $request): Response
    {
        $institution = $this->getInstitutionOrDeny();
        $searchTerm = trim((string)$request->query->get('q'));
        
        $userRepo = $this->em->getRepository(User::class);

        // Subquery: User, die schon Participant sind
        $subQb = $this->em->createQueryBuilder()
            ->select('IDENTITY(p.user)')
            ->from(Participant::class, 'p')
            ->where('p.institution = :inst');

        $qb = $userRepo->createQueryBuilder('u');
        $qb->where($qb->expr()->notIn('u.id', $subQb->getDQL()))
           ->andWhere('u.institution = :inst')
           ->setParameter('inst', $institution);

        if ($searchTerm !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(u.username) LIKE :search',
                    'LOWER(u.firstname) LIKE :search',
                    'LOWER(u.lastname) LIKE :search',
                    'LOWER(u.act) LIKE :search'
                )
            )
            ->setParameter('search', '%' . mb_strtolower($searchTerm) . '%');
        }

        $qb->orderBy('u.lastname', 'ASC')
           ->addOrderBy('u.firstname', 'ASC')
           ->setMaxResults(51);

        $results = $qb->getQuery()->getResult();
        $limitReached = count($results) > 50;
        if ($limitReached) array_pop($results);

        return $this->render('admin/participants/missing.html.twig', [
            'missingUsers' => $results,
            'searchTerm'   => $searchTerm,
            'limitReached' => $limitReached,
            'activeTab'    => 'participants_manage'
        ]);
    }

    #[Route('/add/{id}', name: 'add')]
    public function add(Request $request, int $id): Response
    {
        $institution = $this->getInstitutionOrDeny();
        $user = $this->em->getRepository(User::class)->find($id);

        // ... (Lizenzcheck und Validierung wie gehabt) ...

        $participant = new Participant();
        $participant->setUser($user);
        $participant->setInstitution($institution);
        
        // WICHTIG: Wenn der User ein Admin/Prüfer ist, nehmen wir 
        // falls vorhanden seine Daten, sonst Standard
        $participant->setBirthdate(new \DateTime('1984-01-01')); // Vorbelegung für dich
        $participant->setGender('MALE');

        $form = $this->createParticipantForm($participant, 'Teilnehmer-Profil vervollständigen');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Jeder der hier landet, bekommt die Rolle PARTICIPANT zusätzlich
            $roles = $user->getRoles();
            $roles[] = 'ROLE_PARTICIPANT';
            $user->setRoles(array_unique($roles));

            // Gruppe "Sonstige" suchen oder zuweisen
            $groupRepo = $this->em->getRepository(Group::class);
            $sonstige = $groupRepo->findOneBy(['name' => 'Sonstige', 'institution' => $institution]);
            
            if ($sonstige) {
                $user->addGroup($sonstige);
            }

            $this->em->persist($participant);
            $this->em->flush();
            
            $this->addFlash('success', $user->getFirstname() . ' nimmt nun am Sportabzeichen teil.');
            return $this->redirectToRoute('admin_participants_index');
        }

        return $this->render('admin/participants/add.html.twig', [
            'form' => $form->createView(),
            'user' => $user
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Participant $participant): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $participant->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiger Token.');
            return $this->redirectToRoute('admin_participants_index');
        }

        if ($participant->getInstitution() !== $this->getInstitutionOrDeny()) {
            throw new AccessDeniedException('Nicht autorisiert.');
        }

        $user = $participant->getUser();
        $name = $user ? "$user" : 'Unbekannt'; // Nutzt __toString falls vorhanden, sonst manuell bauen

        // User löschen, wenn kein Import
        if ($user && empty($user->getImportId())) {
            $this->em->remove($user);
        }
        
        $this->em->remove($participant);
        $this->em->flush();
        $this->addFlash('success', 'Gelöscht.');

        return $this->redirectToRoute('admin_participants_index');
    }

    #[Route('/{id}/update', name: 'update', methods: ['POST'])]
    public function update(Request $request, Participant $participant): Response
    {
        $institution = $this->getInstitutionOrDeny();
        if ($participant->getInstitution() !== $institution) {
            throw new AccessDeniedException();
        }

        $user = $participant->getUser();
        $email = trim((string)$request->request->get('email'));
        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');
        $groupId = $request->request->get('group_id');

        // 1. E-Mail & Token-Logik
        if ($user) {
            if (!empty($email)) {
                // E-Mail Dubletten-Check
                $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($existing && $existing->getId() !== $user->getId()) {
                    $this->addFlash('danger', 'E-Mail wird bereits verwendet.');
                    return $this->redirectToRoute('admin_participants_index');
                }
                $user->setEmail($email);
                
                // Automatisch Token generieren, wenn E-Mail vorhanden aber kein Token da ist
                if (!$user->getLoginToken()) {
                    $user->setLoginToken(bin2hex(random_bytes(16)));
                }
            } else {
                $user->setEmail(null);
                // Optional: Token löschen, wenn E-Mail entfernt wird? 
                // $user->setLoginToken(null); 
            }

            // Gruppe aktualisieren
            foreach ($user->getGroups() as $oldGroup) { $user->removeGroup($oldGroup); }
            if ($groupId) {
                $group = $this->em->getRepository(Group::class)->find($groupId);
                if ($group && $group->getInstitution() === $institution) { $user->addGroup($group); }
            }
        }

        // 2. Participant Daten
        if ($dob) { $participant->setBirthdate(new \DateTime($dob)); }
        if (in_array($gender, ['MALE', 'FEMALE'])) { $participant->setGender($gender); }

        $participant->setUpdatedAt(new \DateTime());
        $this->em->flush();

        $this->addFlash('success', 'Daten für ' . ($user ? $user->getFirstname() : 'Teilnehmer') . ' gespeichert.');
        return $this->redirectToRoute('admin_participants_index');
    }


    #[Route('/new', name: 'new')]
    public function new(): Response
    {
        $institution = $this->getInstitutionOrDeny();
        
        $groups = $this->em->getRepository(Group::class)->findBy(
            ['institution' => $institution], ['name' => 'ASC']
        );

        return $this->render('admin/participants/new.html.twig', [
            'activeTab' => 'participants_manage',
            'availableGroups' => $groups,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $institution = $this->getInstitutionOrDeny();

        if ($institution->getParticipantCount() >= $institution->getLicenseLimit()) {
            $this->addFlash('danger', 'Das Lizenzlimit von ' . $institution->getLicenseLimit() . ' Teilnehmern ist erreicht. Anlage abgebrochen.');
            return $this->redirectToRoute('admin_participants_index');
        }

        try {
            $user = new User();
            $user->setFirstname(trim((string)$request->request->get('firstname')));
            $user->setLastname(trim((string)$request->request->get('lastname')));
            
            // 1. Optionaler Login / Benutzername mit Fallback
            $act = trim((string)$request->request->get('act'));
            if (empty($act)) {
                // Generiert eine eindeutige ID, falls nichts angegeben wurde (z.B. user_5f4a1b)
                $act = 'user_' . substr(uniqid(), -8);
            }
            $user->setAct($act);
            $user->setUsername($act); 
            
            $user->setInstitution($institution);
            $user->setPassword('manual_entry');
            
            // Wichtig: array_unique nutzen oder direkt beide Rollen setzen, damit ROLE_USER nicht überschrieben wird
            $user->setRoles(['ROLE_USER', 'ROLE_PARTICIPANT']); 

            // 2. Gruppen-Logik (Bestehende oder Neue)
            $groupId = $request->request->get('group');
            if ($groupId === 'new') {
                $newGroupName = trim((string)$request->request->get('new_group_name'));
                if (!empty($newGroupName)) {
                    $group = new Group();
                    $group->setName($newGroupName);
                    $group->setInstitution($institution);
                    $this->em->persist($group);
                    $user->addGroup($group); // User direkt hinzufügen
                }
            } elseif (!empty($groupId)) {
                $group = $this->em->getRepository(Group::class)->find($groupId);
                if ($group && $group->getInstitution() === $institution) {
                    $user->addGroup($group);
                }
            }
            $this->em->persist($user);

            // 3. Participant anlegen
            $participant = new Participant();
            $participant->setUser($user);
            $participant->setInstitution($institution);
            $participant->setBirthdate(new \DateTime($request->request->get('dob')));
            
            // Divers herausgefiltert, falls manipuliert wird, greift der Fallback
            $gender = $request->request->get('gender');
            $participant->setGender(in_array($gender, ['MALE', 'FEMALE']) ? $gender : 'MALE'); 
            
            $participant->setUpdatedAt(new \DateTime());
            $this->em->persist($participant);

            // 4. Automatischer Prüfungs-Abgleich über die Gruppe
            // Nur ausführen, wenn wir eine Gruppe haben
            // 4. Automatischer Prüfungs-Abgleich über die Gruppe
            // Nur ausführen, wenn wir eine Gruppe haben UND diese bereits eine ID hat (also keine ganz neue ist)
            if (isset($group) && $group->getId() !== null) {
                
                $activeExams = $this->em->getRepository(\App\Entity\Exam::class)
                    ->createQueryBuilder('e')

                // Für jede gefundene Prüfung wird der Teilnehmer automatisch hinzugefügt
                foreach ($activeExams as $exam) {
                    $examParticipant = new \App\Entity\ExamParticipant();
                    $examParticipant->setExam($exam);
                    $examParticipant->setParticipant($participant);
                    
                    // FEHLER-BEHEBUNG: Das age_year berechnen (Prüfungsjahr minus Geburtsjahr)
                    $birthYear = (int)$participant->getBirthdate()->format('Y');
                    $ageYear = $exam->getYear() - $birthYear;
                    $examParticipant->setAgeYear($ageYear);
                    
                    // WICHTIG: Falls du noch Felder wie 'status' hast (in deinem Log stand "NONE"), hier setzen:
                    // $examParticipant->setStatus('NONE'); 

                    $this->em->persist($examParticipant);
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'Teilnehmer erfolgreich angelegt.');

            return $this->redirectToRoute('admin_participants_index');

        } catch (\Exception $e) {
            // DAS HIER KURZ EINFÜGEN:
            dd($e->getMessage()); 
            
            $this->addFlash('danger', 'Fehler beim Speichern: ' . $e->getMessage());
            return $this->redirectToRoute('admin_participants_new');
        }
    }

    private function createParticipantForm(Participant $participant, string $label): \Symfony\Component\Form\FormInterface
    {
        return $this->createFormBuilder($participant)
            ->add('birthdate', DateType::class, ['widget' => 'single_text', 'label' => 'Geburtsdatum'])
            ->add('gender', ChoiceType::class, [
                // "Divers" hier ebenfalls entfernt
                'choices' => ['Männlich' => 'MALE', 'Weiblich' => 'FEMALE'],
                'expanded' => true,
                'label' => 'Geschlecht'
            ])
            ->add('save', SubmitType::class, ['label' => $label, 'attr' => ['class' => 'btn btn-success']])
            ->getForm();
    }

    #[Route('/bulk-assign-group', name: 'bulk_assign_group', methods: ['POST'])]
    public function bulkAssignGroup(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $institution = $this->getInstitutionOrDeny();
        $data = json_decode($request->getContent(), true);
        
        $userIds = $data['ids'] ?? [];
        $groupId = $data['groupId'] ?? null;

        if (empty($userIds) || !$groupId) {
            return $this->json(['success' => false, 'message' => 'Daten unvollständig'], 400);
        }

        $group = $this->em->getRepository(Group::class)->find($groupId);
        if (!$group || $group->getInstitution() !== $institution) {
            return $this->json(['success' => false, 'message' => 'Gruppe nicht gefunden'], 403);
        }

        $users = $this->em->getRepository(User::class)->findBy([
            'id' => $userIds,
            'institution' => $institution
        ]);

        foreach ($users as $user) {
            // Falls ein User nur in einer Gruppe sein darf, hier optional:
            // foreach($user->getGroups() as $oldGroup) { $user->removeGroup($oldGroup); }
            
            if (!$user->getGroups()->contains($group)) {
                $user->addGroup($group);
            }
        }

        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/group/{id}/print-logins', name: 'group_print_logins')]
    public function printGroupQrCodes(\App\Entity\Group $group, \App\Repository\ParticipantRepository $repo): \Symfony\Component\HttpFoundation\Response
    {
        // Holt alle Teilnehmer der Gruppe
        $participants = $repo->findBy(['group' => $group]);

        return $this->render('admin/participants/qr_print.html.twig', [
            'participants' => $participants,
            'group' => $group,
        ]);
    }

    #[Route('/{id}/print-qr', name: 'show_qr')]
    public function showQr(\App\Entity\Participant $participant): \Symfony\Component\HttpFoundation\Response
    {
        // Einzeldruck für nur einen Teilnehmer
        return $this->render('admin/participants/qr_print.html.twig', [
            'participant' => $participant,
        ]);
    }
}