#[Route('/users', name: 'app_user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        // 1. Eigene Schule holen
        $currentUser = $this->getUser();
        $myInstitution = $currentUser->getInstitution();

        // 2. Sicherheitscheck (darf eigentlich nicht passieren)
        if (!$myInstitution) {
            throw $this->createAccessDeniedException('Keine Institution zugewiesen!');
        }

        // 3. WICHTIG: Nur User MEINER Schule laden
        // NICHT: $userRepository->findAll();
        $users = $userRepository->findBy(['institution' => $myInstitution]);

        return $this->render('user/index.html.twig', [
            'users' => $users,
        ]);
    }