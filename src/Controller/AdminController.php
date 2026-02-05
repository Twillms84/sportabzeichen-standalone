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
        return $this->render('admin/dashboard.html.twig', [
            'activeTab' => 'dashboard',
        ]);
    }

    #[Route('/participants', name: 'participants_index')]
    public function participantsIndex(Request $request, Connection $conn): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;
        $q = trim((string)$request->query->get('q')); 

        // --- 1. SQL-Fragmente vorbereiten ---
        $searchSql = '';
        $params = [];

        // Basis-Join: Participant -> User
        $joinSql = " 
            FROM sportabzeichen_participants p 
            LEFT JOIN users u ON p.user_id = u.id 
            WHERE 1=1 
        ";

        if ($q !== '') {
            $searchSql = " AND (
                u.lastname ILIKE :search OR 
                u.firstname ILIKE :search OR 
                u.act ILIKE :search OR
                u.username ILIKE :search OR
                u.import_id ILIKE :search
            )";
            $params['search'] = '%' . $q . '%';
        }

        // --- 2. ZÄHLEN ---
        $countSql = "SELECT COUNT(p.id)" . $joinSql . $searchSql;
        $totalCount = (int) $conn->fetchOne($countSql, $params);
        $maxPages = max(1, (int) ceil($totalCount / $limit));

        // --- 3. DATEN LADEN ---
        // Wir holen die Gruppennamen per Sub-Select aus der neuen users_groups Tabelle
        $dataSql = "
            SELECT 
                p.*, 
                u.firstname as u_firstname, 
                u.lastname as u_lastname, 
                u.act AS iserv_account,
                u.source AS user_source,
                u.import_id AS user_import_id,
                (
                    SELECT STRING_AGG(g.name, ', ')
                    FROM \"groups\" g
                    INNER JOIN users_groups ug ON g.id = ug.group_id
                    WHERE ug.user_id = u.id
                ) AS group_name
            " . $joinSql . $searchSql . "
            ORDER BY 
                u.lastname ASC, 
                u.firstname ASC
            LIMIT :limit OFFSET :offset
        ";

        $params['limit'] = $limit;
        $params['offset'] = $offset;

        try {
            $participants = $conn->fetchAllAssociative($dataSql, $params);
        } catch (\Exception $e) {
            // Fallback für den Notfall
            $this->addFlash('error', 'Datenbankfehler beim Laden der Liste: ' . $e->getMessage());
            $participants = [];
        }

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
        $userRepo = $this->em->getRepository(User::class);
        $searchTerm = trim((string)$request->query->get('q'));
        
        // Subquery: User, die schon Participant sind
        $completedSubQuery = $this->em->createQueryBuilder()
            ->select('IDENTITY(p.user)')
            ->from(Participant::class, 'p')
            ->where('p.user IS NOT NULL')
            ->getDQL();

        $qb = $userRepo->createQueryBuilder('u')
            ->where('u.id NOT IN (' . $completedSubQuery . ')');
            // 'deleted' Check entfernt, da das Feld in der neuen Entity ggf. nicht mehr existiert 
            // oder anders gehandhabt wird. Falls du SoftDelete nutzt, hier wieder einfügen.
        
        if ($searchTerm !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(u.username)', ':s'),
                    $qb->expr()->like('LOWER(u.firstname)', ':s'),
                    $qb->expr()->like('LOWER(u.lastname)', ':s'),
                    $qb->expr()->like('LOWER(u.act)', ':s'),
                    $qb->expr()->like('LOWER(u.importId)', ':s')
                )
            )
            ->setParameter('s', '%' . mb_strtolower($searchTerm) . '%');
        }

        $qb->orderBy('u.lastname', 'ASC')
           ->addOrderBy('u.firstname', 'ASC')
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

    #[Route('/participants/add/{id}', name: 'participants_add')]
    public function participantsAdd(Request $request, int $id): Response
    {
        // Wir suchen jetzt explizit nach ID, das ist sicherer als Username
        $user = $this->em->getRepository(User::class)->find($id);

        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('admin_participants_missing');
        }

        // Prüfung ob schon existiert
        $existing = $this->em->getRepository(Participant::class)->findOneBy(['user' => $user]);
        if ($existing) {
            $this->addFlash('warning', 'Teilnehmer existiert bereits.');
            return $this->redirectToRoute('admin_participants_missing');
        }

        $participant = new Participant();
        $participant->setUser($user);
        
        // Neue Felder nutzen
        $participant->setUpdatedAt(new \DateTime());
        
        // Standardwerte (kann im Formular geändert werden)
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

    #[Route('/participants/{id}/update', name: 'participants_update', methods: ['POST'])]
    public function participantsUpdate(Request $request, Participant $participant): Response
    {
        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');

        if ($dob) {
            try {
                $participant->setBirthdate(new \DateTime($dob));
            } catch (\Exception $e) {}
        }

        if ($gender && in_array($gender, ['MALE', 'FEMALE', 'DIVERSE'], true)) {
             $participant->setGender($gender);
        }

        $participant->setUpdatedAt(new \DateTime());
        
        $this->em->flush();
        $this->addFlash('success', 'Daten gespeichert.');
        
        return $this->redirectToRoute('admin_participants_index');
    }

    #[Route('/participants/edit/{id}', name: 'participants_edit')]
    public function participantsEdit(Request $request, Participant $participant): Response
    {
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

    /**
     * Hinweis: Die eigentliche Import-Logik liegt im ParticipantUploadController.
     * Diese Route hier ist optional, falls du nur das Template rendern willst,
     * aber der Link im Menü sollte auf den Upload-Controller zeigen.
     */
    #[Route('/participants_upload_view', name: 'participants_upload_view')]
    public function importIndex(): Response
    {
        return $this->render('admin/upload_participants.html.twig', [
            'activeTab' => 'import',
            'message' => null, 'error' => null, 'imported' => 0, 'skipped' => 0
        ]);
    }

    private function createParticipantForm(Participant $participant, string $btnLabel): \Symfony\Component\Form\FormInterface
    {
        return $this->createFormBuilder($participant)
            ->add('birthdate', DateType::class, [  // HIER: 'birthdate' statt 'geburtsdatum'
                'label' => 'Geburtsdatum',         // Das Label bleibt deutsch
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('gender', ChoiceType::class, [   // HIER: 'gender' statt 'geschlecht'
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