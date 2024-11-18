<?php

namespace App\Controller;

use App\Form\SetPasswordFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

class SecurityController extends AbstractController
{
    #[Route(path: '/', name: 'app_home')]
    public function home(): Response
    {
        return $this->redirectToRoute('app_login');
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_meeting_index');
        }
        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }



    #[Route('/set-password/{token}', name: 'app_user_set_password', methods: ['GET', 'POST'])]
    public function setPassword(
        Request $request,
        string $token,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $userRepository->findOneBy(['resetToken' => $token]);

        if (!$user) {
            throw $this->createNotFoundException('Token invalide');
        }

        $form = $this->createForm(SetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Hachage du mot de passe
            $hashedPassword = $passwordHasher->hashPassword(
                $user,
                $form->get('plainPassword')->getData()
            );

            // Définition du mot de passe haché sur l'entité User
            $user->setPassword($hashedPassword);

            // Réinitialisation du token (pour qu'il ne puisse plus être utilisé)
            $user->setResetToken(null);

            // Enregistrement des modifications en base de données
            $entityManager->flush();

            $this->addFlash('success', 'Votre mot de passe a été créé avec succès.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('user/set_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }
    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepository,
        TokenGeneratorInterface $tokenGenerator,
        MailerInterface $mailer,
        EntityManagerInterface $entityManager,
        Environment $twig // Ajout du moteur de rendu Twig
    ): Response {
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $user = $userRepository->findOneBy(['email' => $email]);

            if (!$user) {
                // Ajouter un message flash en cas d'email invalide
                $this->addFlash('error', 'Aucun utilisateur trouvé avec cet email.');
                return $this->redirectToRoute('app_forgot_password');
            }

            // Générer un token de réinitialisation
            $resetToken = $tokenGenerator->generateToken();
            $user->setResetToken($resetToken);
            $entityManager->flush();

            // Créer l'URL de réinitialisation
            $resetUrl = $this->generateUrl('app_user_set_password', ['token' => $resetToken], UrlGeneratorInterface::ABSOLUTE_URL);

            // Rendre le contenu de l'email depuis le template Twig
            $emailContent = $twig->render('emails/forgot_password.html.twig', [
                'user' => $user,
                'resetUrl' => $resetUrl
            ]);

            // Créer l'email en utilisant le contenu rendu par Twig
            $email = (new Email())
                ->from('noreply@yourdomain.com')
                ->to($user->getEmail())
                ->subject('Réinitialisation de votre mot de passe')
                ->html($emailContent);

            // Envoyer l'email
            $mailer->send($email);

            // Ajouter un message flash pour informer l'utilisateur que l'email a été envoyé
            $this->addFlash('success', 'Un e-mail de réinitialisation de mot de passe a été envoyé.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/forgot_password.html.twig');
    }

}
