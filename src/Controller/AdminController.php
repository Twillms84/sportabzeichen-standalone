<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Participant;
use App\Entity\User;
use App\Entity\Group;
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

#[Route('/admin', name: 'admin_')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    #[Route('/', name: 'dashboard')]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'activeTab' => 'dashboard',
        ]);
    }

    #[Route('/participants', name: 'participants_index')]
    public function participantsIndex(Request $request): Response
    {
        // 1. Sicherheit: Institution prüfen
        $user = $this->getUser();
        $institution = $user ? $user->getInstitution() : null;

        if (!$institution) {
            throw new AccessDeniedException('Keine Institution zugewiesen.');
        }

        // 2. Parameter holen
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;
        $q = trim((string)$request->query->get('q'));

        // 3. QueryBuilder erstellen (ORM statt SQL)
        $qb = $this->em->getRepository(Participant::class)->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')
            ->addSelect('u') // User direkt mitladen
            ->leftJoin('u.groups', 'g')
            ->addSelect('g') // Gruppen direkt mitladen (vermeidet N+1 Problem)
            ->where('p.institution = :institution')
            ->setParameter('institution', $institution)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC');

        // 4. Suchfilter anwenden
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

        // 5. Pagination mit Doctrine Paginator (wichtig bei Joins!)
        $query = $qb->getQuery();
        $query->setFirstResult(($page - 1) * $limit)
              ->setMaxResults($limit);

        // Der Paginator berechnet automatisch den korrekten Count, auch mit Joins
        $paginator = new Paginator($query, fetchJoinCollection: true);
        $totalCount = count($paginator);
        $maxPages = max(1, (int) ceil($totalCount / $limit));

        return $this->render('admin/participants/index.html.twig', [
            'participants' => $paginator, // Das ist jetzt iterierbar wie ein Array
            'activeTab'    => 'participants_manage',
            'currentPage'  => $page,
            'maxPages'     => $maxPages,
            'totalCount'   => $totalCount,
            'searchTerm'   => $q,
        ]);
    }

    #[Route('/participants/missing', name: 'participants_missing')]
    public function participantsMissing(Request $request): Response
    {
        $user = $this->getUser();
        $institution = $user ? $user->getInstitution() : null;

        if (!$institution) {
            throw new AccessDeniedException('Keine Institution zugewiesen.');
        }

        $searchTerm = trim((string)$request->query->get('q'));
        $userRepo = $this->em->getRepository(User::class);

        // Subquery: Finde alle User-IDs, die schon in Participant sind
        // Wichtig: Wir schauen nur in die Participants dieser Institution (optional, aber sauberer)
        $subQb = $this->em->createQueryBuilder()
            ->select('IDENTITY(p.user)')
            ->from(Participant::class, 'p')
            ->where('p.institution = :inst');

        $qb = $userRepo->createQueryBuilder('u');
        
        $qb->where($qb->expr()->notIn('u.id', $subQb->getDQL()))
           ->andWhere('u.institution = :inst') // Nur User meiner Schule
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
           ->setMaxResults(51); // Eins mehr holen, um zu prüfen ob es noch mehr gibt

        $results = $qb->getQuery()->getResult();

        $limitReached = false;
        if (count($results) > 50) {
            $limitReached = true;
            array_pop($results); // Den 51. Eintrag wieder entfernen
        }

        return $this->render('admin/participants/missing.html.twig', [
            'missingUsers' => $results,
            'searchTerm'   => $searchTerm,
            'limitReached' => $limitReached,
            'activeTab'    => 'participants_manage'
        ]);
    }

    #[Route('/participants/add/{id}', name: 'participants_add')]
    public function participantsAdd(Request $request, int $id): Response
    {
        $currentUser = $this->getUser();
        $institution = $currentUser ? $currentUser->getInstitution() : null;

        if (!$institution) {
            throw new AccessDeniedException('Keine Institution.');
        }

        $user = $this->em->getRepository(User::class)->find($id);

        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('admin_participants_missing');
        }

        // Sicherheitscheck: Gehört der User mir?
        if ($user->getInstitution() !== $institution) {
            throw new AccessDeniedException('Zugriff verweigert.');
        }

        // Check: Existiert schon?
        $existing = $this->em->getRepository(Participant::class)->findOneBy(['user' => $user]);
        if ($existing) {
            $this->addFlash('warning', 'Teilnehmer existiert bereits.');
            return $this->redirectToRoute('admin_participants_missing');
        }

        $participant = new Participant();
        $participant->setUser($user);
        $participant->setInstitution($institution); // <--- WICHTIG!
        $participant->setUpdatedAt(new \DateTime());
        
        // Defaults
        $participant->setBirthdate(new \DateTime('2010-01-01'));
        $participant->setGender('MALE');

        $form = $this->createParticipantForm($participant, 'Teilnehmer anlegen');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->em->persist($participant);
                $this->em->flush();
                
                $name = $user->getFirstname() . ' ' . $user->getLastname();
                $this->addFlash('success', 'Teilnehmer angelegt: ' . $name);
                
                return $this->redirectToRoute('admin_participants_missing');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Fehler: ' . $e->getMessage());
            }
        }

        return $this->render('admin/participants/add.html.twig', [
            'form' => $form->createView(),
            'user' => $user
        ]);
    }

    #[Route('/participants/edit/{id}', name: 'participants_edit')]
    public function participantsEdit(Request $request, Participant $participant): Response
    {
        // 1. SICHERHEIT: Gehört der Teilnehmer zu meiner Institution?
        if ($participant->getInstitution() !== $this->getUser()->getInstitution()) {
            throw $this->createAccessDeniedException('Dieser Teilnehmer gehört nicht zu Ihrer Institution.');
        }

        $form = $this->createParticipantForm($participant, 'Änderungen speichern');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $participant->setUpdatedAt(new \DateTime());
                $this->em->flush(); 
                $this->addFlash('success', 'Daten aktualisiert.');
                return $this->redirectToRoute('admin_participants_index');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Fehler: ' . $e->getMessage());
            }
        }

        return $this->render('admin/participants/add.html.twig', [
            'form' => $form->createView(),
            'user' => $participant->getUser()
        ]);
    }
    #[Route('/participants/{id}/delete', name: 'participants_delete', methods: ['POST'])]
    public function participantsDelete(Request $request, Participant $participant): Response
    {
        // Sicherheit: CSRF-Token prüfen
        if (!$this->isCsrfTokenValid('delete' . $participant->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiger Sicherheits-Token.');
            return $this->redirectToRoute('admin_participants_index');
        }

        // Sicherheit: Institution prüfen
        if ($participant->getInstitution() !== $this->getUser()->getInstitution()) {
            throw new AccessDeniedException('Nicht autorisiert.');
        }

        $user = $participant->getUser();
        $name = $user ? $user->getFirstname() . ' ' . $user->getLastname() : 'Unbekannt';

        try {
            // Logik: User nur löschen, wenn er keine importId hat (nicht von eduplaces kommt)
            // und nur wenn er keine anderen Rollen/Beziehungen hat (optional)
            if ($user && empty($user->getImportId())) {
                $this->em->remove($user);
                $this->addFlash('info', "Account von $name wurde ebenfalls gelöscht.");
            }

            $this->em->remove($participant);
            $this->em->flush();

            $this->addFlash('success', "Teilnehmer $name wurde gelöscht.");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Löschen: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_participants_index');
    }

    #[Route('/participants/{id}/update', name: 'participants_update', methods: ['POST'])]
    public function participantsUpdate(Request $request, Participant $participant): Response
    {
        // 1. Sicherheits-Check: Gehört der Teilnehmer zu meiner Institution?
        $currentUser = $this->getUser();
        $institution = $currentUser ? $currentUser->getInstitution() : null;

        if ($participant->getInstitution() !== $institution) {
             throw new AccessDeniedException('Zugriff verweigert.');
        }

        // 2. Daten aus dem Request holen
        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');

        // 3. Update durchführen
        if ($dob) {
            try {
                $participant->setBirthdate(new \DateTime($dob));
            } catch (\Exception $e) {
                // Bei ungültigem Datum ignorieren oder Fehler werfen
            }
        }

        if ($gender && in_array($gender, ['MALE', 'FEMALE', 'DIVERSE'], true)) {
             $participant->setGender($gender);
        }

        $participant->setUpdatedAt(new \DateTime());
        
        // 4. Speichern via EntityManager
        $this->em->flush();
        
        $this->addFlash('success', 'Daten gespeichert.');
        
        return $this->redirectToRoute('admin_participants_index');
    }

    #[Route('/participants/new', name: 'participants_new')]
    public function participantsNew(): Response
    {
        $user = $this->getUser();
        $institution = $user ? $user->getInstitution() : null;

        if (!$institution) {
            throw new AccessDeniedException('Keine Institution zugewiesen.');
        }

        // Alle Gruppen der Institution für das Dropdown holen
        $groups = $this->em->getRepository(Group::class)->findBy(
            ['institution' => $institution],
            ['name' => 'ASC']
        );

        return $this->render('admin/participants/new.html.twig', [
            'activeTab' => 'participants_manage',
            'availableGroups' => $groups,
        ]);
    }

    #[Route('/participants/create', name: 'participants_create', methods: ['POST'])]
    public function participantsCreate(Request $request): Response
    {
        $currentUser = $this->getUser();
        $institution = $currentUser ? $currentUser->getInstitution() : null;

        if (!$institution) {
            throw new AccessDeniedException('Keine Institution.');
        }

        // 1. Daten aus Request holen
        $firstname = $request->request->get('firstname');
        $lastname = $request->request->get('lastname');
        $act = $request->request->get('act'); // IServ-Account
        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');
        $groupId = $request->request->get('group');

        try {
            // 2. User anlegen
            $user = new User();
            $user->setFirstname($firstname);
            $user->setLastname($lastname);
            $user->setAct($act);
            $user->setUsername($act); // Oft identisch mit ACT
            $user->setInstitution($institution);
            $user->setRoles(['ROLE_USER']);
            $user->setPassword('manual_entry'); // Oder ein Random-Passwort

            // Gruppe zuweisen
            if ($groupId) {
                $group = $this->em->getRepository(Group::class)->find($groupId);
                if ($group && $group->getInstitution() === $institution) {
                    $user->addGroup($group);
                }
            }

            $this->em->persist($user);

            // 3. Teilnehmer anlegen
            $participant = new Participant();
            $participant->setUser($user);
            $participant->setInstitution($institution);
            $participant->setBirthdate(new \DateTime($dob));
            $participant->setGender($gender);
            $participant->setUpdatedAt(new \DateTime());

            $this->em->persist($participant);
            $this->em->flush();

            $this->addFlash('success', "Teilnehmer $firstname $lastname wurde erfolgreich angelegt.");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Anlegen: ' . $e->getMessage());
            return $this->redirectToRoute('admin_participants_new');
        }

        return $this->redirectToRoute('admin_participants_index');
    }

    private function createParticipantForm(Participant $participant, string $btnLabel): \Symfony\Component\Form\FormInterface
    {
        return $this->createFormBuilder($participant)
            ->add('birthdate', DateType::class, [
                'label' => 'Geburtsdatum',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('gender', ChoiceType::class, [
                'label' => 'Geschlecht',
                'choices' => [
                    'Männlich' => 'MALE',
                    'Weiblich' => 'FEMALE',
                    'Divers'   => 'DIVERSE',
                ],
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('save', SubmitType::class, [
                'label' => $btnLabel,
                'attr' => ['class' => 'btn btn-success']
            ])
            ->getForm();
    }
}