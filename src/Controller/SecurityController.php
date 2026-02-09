namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Falls der User schon eingeloggt ist, schick ihn zum Dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_exams_dashboard');
        }

        // Fehler holen, falls der Login fehlgeschlagen ist
        $error = $authenticationUtils->getLastAuthenticationError();
        // Letzter Benutzername, den der User eingegeben hat
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername, 
            'error' => $error
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Diese Methode bleibt leer, Symfony fängt den Request ab
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}