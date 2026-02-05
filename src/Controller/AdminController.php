<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\User;
use App\Entity\Participant;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
        // $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        return $this->render('admin/dashboard.html.twig', [
            'activeTab' => 'dashboard',
        ]);
    }

    #[Route('/participants', name: 'participants_index')]
    public function participantsIndex(Request $request, Connection $conn): Response
    {
        // $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;
        $q = trim((string)$request->query->get('q')); // Suchbegriff

        // --- 1. SQL-Fragmente vorbereiten ---
        
        $searchSql = '';
        $params = [];

        // JOIN: Users (für Namen) UND Groups (für Klassen/Riegen)
        // Wir gehen davon aus, dass in sportabzeichen_participants die Spalte 'group_id' existiert.
        $joinSql = " 
            FROM sportabzeichen_participants p 
            LEFT JOIN users u ON p.user_id = u.id 
            LEFT JOIN groups g ON p.group_id = g.id
            WHERE 1=1 
        ";

        // Such-Logik: Wir suchen in User, Participant UND Group
        if ($q !== '') {
            $searchSql = " AND (
                u.lastname ILIKE :search OR 
                u.firstname ILIKE :search OR 
                u.act ILIKE :search OR
                p.username ILIKE :search OR
                g.name ILIKE :search
            )";
            $params['search'] = '%' . $q . '%';
        }

        // --- 2. ZÄHLEN (Pagination) ---
        $countSql = "SELECT COUNT(p.id)" . $joinSql . $searchSql;
        $totalCount = (int) $conn->fetchOne($countSql, $params);
        $maxPages = max(1, (int) ceil($totalCount / $limit));

        // --- 3. DATEN LADEN ---
        // Wir holen zusätzlich 'g.name' als 'group_name'
        $dataSql = "
            SELECT 
                p.*, 
                u.firstname as u_firstname, 
                u.lastname as u_lastname, 
                u.act AS iserv_account,
                g.name AS group_name
            " . $joinSql . $searchSql . "
            ORDER BY 
                COALESCE(u.lastname, p.username) ASC, 
                COALESCE(u.firstname, '') ASC
            LIMIT :limit OFFSET :offset
        ";

        // Limit/Offset zu Parametern hinzufügen
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $participants = $conn->fetchAllAssociative($dataSql, $params);

        return $this->render('admin/participants/index.html.twig', [
            'participants' => $participants,
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
        // $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $userRepo = $this->em->getRepository(User::class);
        $searchTerm = trim((string)$request->query->get('q'));
        
        // --- 1. Subquery: User IDs finden, die schon Teilnehmer sind ---
        $completedSubQuery = $this->em->createQueryBuilder()
            ->select('IDENTITY(p.user)')
            ->from(Participant::class, 'p')
            ->where('p.user IS NOT NULL')
            ->getDQL();

        // --- 2. Hauptquery: Alle User, die NICHT in der Subquery sind ---
        $qb = $userRepo->createQueryBuilder('u')
            ->where('u.deleted IS NULL') // Nur aktive User
            ->andWhere($userRepo->createQueryBuilder('u')->expr()->notIn('u.id', $completedSubQuery));
        
        // Suchelogik
        if ($searchTerm !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(u.username)', ':s'),
                    $qb->expr()->like('LOWER(u.firstname)', ':s'),
                    $qb->expr()->like('LOWER(u.lastname)', ':s'),
                    $qb->expr()->like('LOWER(u.act)', ':s') 
                )
            )
            ->setParameter('s', '%' . mb_strtolower($searchTerm) . '%');
        }

        // Sortierung
        $qb->orderBy('u.lastname', 'ASC')
           ->addOrderBy('u.firstname', 'ASC')
           ->addOrderBy('u.username', 'ASC')
           ->setMaxResults(51);

        $results = $qb->getQuery()->getResult();
        
        $limitReached = false;
        if (count($results) > 50) {
            $limitReached = true;
            array_pop($results);
        }

        return $this->render('admin/participants/missing.html.twig', [
            'missingUsers' => $results,
            'searchTerm'   => $searchTerm,
            'limitReached' => $limitReached,
            'activeTab'    => 'participants_manage'
        ]);
    }

    #[Route('/participants/add/{username}', name: 'participants_add')]
    public function participantsAdd(Request $request, string $username): Response
    {
        // $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);

        if (!$user) {
            $user = $this->em->getRepository(User::class)->findOneBy(['act' => $username]);
        }

        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('admin_participants_missing');
        }

        $existing = $this->em->getRepository(Participant::class)->findOneBy(['user' => $user]);
        if ($existing) {
            $this->addFlash('warning', 'Teilnehmer existiert bereits.');
            return $this->redirectToRoute('admin_participants_missing');
        }

        $participant = new Participant();
        $participant->setUser($user);
        
        $importId = method_exists($user, 'getImportId') && $user->getImportId() 
            ? $user->getImportId() 
            : 'USER_' . $user->getId();
            
        $participant->setImportId($importId);
        
        $participant->setGeburtsdatum(new \DateTime('2010-01-01'));
        $participant->setGeschlecht('MALE');

        $form = $this->createParticipantForm($participant, 'Teilnehmer hinzufügen');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->em->persist($participant);
                $this->em->flush();

                $name = $user->getFirstname() ?: $user->getUsername();
                $this->addFlash('success', 'Gespeichert: ' . $name);
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

    #[Route('/participants/{id}/update', name: 'participants_update', methods: ['POST'])]
    public function participantsUpdate(Request $request, Participant $participant): Response
    {
        // $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');

        if ($dob) {
            try {
                $participant->setGeburtsdatum(new \DateTime($dob));
            } catch (\Exception $e) {
                // Ignore invalid date
            }
        }

        if ($gender && in_array($gender, ['MALE', 'FEMALE', 'DIVERSE'], true)) {
             $participant->setGeschlecht($gender);
        }

        $this->em->flush();
        $this->addFlash('success', 'Daten gespeichert.');

        return $this->redirectToRoute('admin_participants_index');
    }

    #[Route('/participants/edit/{id}', name: 'participants_edit')]
    public function participantsEdit(Request $request, Participant $participant): Response
    {
        // $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $form = $this->createParticipantForm($participant, 'Änderungen speichern');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
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

    #[Route('/participants_upload', name: 'participants_upload')]
    public function importIndex(): Response
    {
        // $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');
        return $this->render('admin/upload_participants.html.twig', [
            'activeTab' => 'import',
            'message' => null, 'error' => null, 'imported' => 0, 'skipped' => 0
        ]);
    }

    private function createParticipantForm(Participant $participant, string $btnLabel): \Symfony\Component\Form\FormInterface
    {
        return $this->createFormBuilder($participant)
            ->add('geburtsdatum', DateType::class, [ 
                'label' => 'Geburtsdatum',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('geschlecht', ChoiceType::class, [
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