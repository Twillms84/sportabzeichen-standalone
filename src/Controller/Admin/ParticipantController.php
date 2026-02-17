<?php

declare(strict_types=1);

namespace App\Controller\Admin; // Neuer Namespace für Admin-Subordner

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

    #[Route('/', name: 'index')] // Ergibt: admin_participants_index
    public function index(Request $request): Response
    {
        $institution = $this->getInstitutionOrDeny();

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;
        $q = trim((string)$request->query->get('q'));

        $qb = $this->em->getRepository(Participant::class)->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')
            ->addSelect('u')
            ->leftJoin('u.groups', 'g')
            ->addSelect('g')
            ->where('p.institution = :institution')
            ->setParameter('institution', $institution)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC');

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

        return $this->render('admin/participants/index.html.twig', [
            'participants' => $paginator,
            'activeTab'    => 'participants_manage',
            'currentPage'  => $page,
            'maxPages'     => $maxPages,
            'totalCount'   => $totalCount,
            'searchTerm'   => $q,
            'searchQuery'  => $q, // Für Kompatibilität mit dem neuen Template
            'pagesCount'   => $maxPages // Für Kompatibilität
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

    #[Route('/add/{id}', name: 'add')] // Ergibt: admin_participants_add
    public function add(Request $request, int $id): Response
    {
        $institution = $this->getInstitutionOrDeny();
        $user = $this->em->getRepository(User::class)->find($id);

        if (!$user || $user->getInstitution() !== $institution) {
            $this->addFlash('error', 'Benutzer nicht gefunden oder Zugriff verweigert.');
            return $this->redirectToRoute('admin_participants_missing');
        }

        $existing = $this->em->getRepository(Participant::class)->findOneBy(['user' => $user]);
        if ($existing) {
            $this->addFlash('warning', 'Teilnehmer existiert bereits.');
            return $this->redirectToRoute('admin_participants_missing');
        }

        $participant = new Participant();
        $participant->setUser($user);
        $participant->setInstitution($institution);
        $participant->setUpdatedAt(new \DateTime());
        $participant->setBirthdate(new \DateTime('2010-01-01'));
        $participant->setGender('MALE');

        $form = $this->createParticipantForm($participant, 'Teilnehmer anlegen');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($participant);
            $this->em->flush();
            $this->addFlash('success', 'Teilnehmer angelegt: ' . $user->getFirstname());
            return $this->redirectToRoute('admin_participants_missing');
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
        if ($participant->getInstitution() !== $this->getInstitutionOrDeny()) {
            throw new AccessDeniedException();
        }

        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');

        if ($dob) {
            try { $participant->setBirthdate(new \DateTime($dob)); } catch (\Exception $e) {}
        }
        if ($gender && in_array($gender, ['MALE', 'FEMALE', 'DIVERSE'])) {
            $participant->setGender($gender);
        }
        
        $participant->setUpdatedAt(new \DateTime());
        $this->em->flush();
        $this->addFlash('success', 'Aktualisiert.');
        
        // Zurück zur Liste mit Query-Parametern wenn möglich, sonst clean
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

        try {
            $user = new User();
            $user->setFirstname($request->request->get('firstname'));
            $user->setLastname($request->request->get('lastname'));
            $user->setAct($request->request->get('act'));
            $user->setUsername($request->request->get('act')); 
            $user->setInstitution($institution);
            $user->setRoles(['ROLE_USER']);
            $user->setPassword('manual_entry');

            $groupId = $request->request->get('group');
            if ($groupId) {
                $group = $this->em->getRepository(Group::class)->find($groupId);
                if ($group && $group->getInstitution() === $institution) {
                    $user->addGroup($group);
                }
            }
            $this->em->persist($user);

            $participant = new Participant();
            $participant->setUser($user);
            $participant->setInstitution($institution);
            $participant->setBirthdate(new \DateTime($request->request->get('dob')));
            $participant->setGender($request->request->get('gender'));
            $participant->setUpdatedAt(new \DateTime());

            $this->em->persist($participant);
            $this->em->flush();

            $this->addFlash('success', 'Teilnehmer angelegt.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler: ' . $e->getMessage());
            return $this->redirectToRoute('admin_participants_new');
        }

        return $this->redirectToRoute('admin_participants_index');
    }

    private function createParticipantForm(Participant $participant, string $label): \Symfony\Component\Form\FormInterface
    {
        return $this->createFormBuilder($participant)
            ->add('birthdate', DateType::class, ['widget' => 'single_text', 'label' => 'Geburtsdatum'])
            ->add('gender', ChoiceType::class, [
                'choices' => ['Männlich' => 'MALE', 'Weiblich' => 'FEMALE', 'Divers' => 'DIVERSE'],
                'expanded' => true,
                'label' => 'Geschlecht'
            ])
            ->add('save', SubmitType::class, ['label' => $label, 'attr' => ['class' => 'btn btn-success']])
            ->getForm();
    }
}