<?php

namespace App\Controller\Admin;

use App\Entity\Group;
use App\Repository\GroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/groups', name: 'app_admin_group_')]
class GroupController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(GroupRepository $groupRepository): Response
    {
        // Wir holen nur die Gruppen der Institution des aktuell eingeloggten Nutzers
        $institution = $this->getUser()->getInstitution();
        
        $groups = $groupRepository->findBy(
            ['institution' => $institution],
            ['name' => 'ASC'] // Alphabetisch sortieren
        );

        return $this->render('admin/group/index.html.twig', [
            'groups' => $groups,
        ]);
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request, GroupRepository $groupRepository, EntityManagerInterface $em): JsonResponse
    {
        // Daten aus dem AJAX-Request holen
        $id = $request->request->get('id');
        $name = trim((string) $request->request->get('name'));

        if (empty($name)) {
            return new JsonResponse(['success' => false, 'message' => 'Der Gruppenname darf nicht leer sein.'], 400);
        }

        $institution = $this->getUser()->getInstitution();

        if ($id) {
            // BESTEHENDE GRUPPE UMBENENNEN
            $group = $groupRepository->find($id);
            
            if (!$group || $group->getInstitution() !== $institution) {
                return new JsonResponse(['success' => false, 'message' => 'Gruppe nicht gefunden oder Zugriff verweigert.'], 403);
            }
        } else {
            // NEUE GRUPPE ANLEGEN
            $group = new Group();
            $group->setInstitution($institution);
            // Falls du den 'act' (Aktivitäts-Status o.ä.) standardmäßig setzen musst, hier ergänzen:
            // $group->setAct('active'); 
        }

        $group->setName($name);
        
        $em->persist($group);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Gruppe erfolgreich gespeichert!',
            'group' => [
                'id' => $group->getId(),
                'name' => $group->getName()
            ]
        ]);
    }
}