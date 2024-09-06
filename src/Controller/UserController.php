<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Form\RegisterFormType;
use App\Form\SetPasswordFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Finder\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mime\Email;

#[Route('/user')]
class UserController extends AbstractController
{
    #[Route('/', name: 'app_user_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }
    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Générer un token unique pour la création du mot de passe
            $token = bin2hex(random_bytes(32));
            $user->setResetToken($token);

            // Définir un mot de passe temporaire (il sera changé par l'utilisateur)
            $user->setPassword('temp_password');

            $entityManager->persist($user);
            $entityManager->flush();

            // Générer l'URL pour la création du mot de passe
            $url = $this->generateUrl('app_user_set_password', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

            // Envoyer l'e-mail
            $email = (new Email())
                ->from('your-email@example.com')
                ->to($user->getEmail())
                ->subject('Créez votre mot de passe')
                ->html("<p>Cliquez sur ce lien pour créer votre mot de passe : <a href=\"{$url}\">Créer mon mot de passe</a></p>");

            $mailer->send($email);

            // Message de succès
            $this->addFlash('success', 'Nouvel utilisateur créé avec succès! Un e-mail a été envoyé pour la création du mot de passe.');

            return $this->redirectToRoute('app_user_index');
        }

        return $this->render('user/new.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }


    #[Route('/profile', name: 'app_user_profile', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function profile(): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException('Vous devez être connecté pour accéder à cette page.');
        }

        return $this->render('user/profile.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}', name: 'app_user_show', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function show(User $user): Response
    {
        if ($user !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à accéder à ce profil.');
        }

        return $this->render('user/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        // Vérification des droits d'accès
        if ($user !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Vous n\'êtes pas autorisé à modifier ce profil.');
        }

        $form = $this->createForm(UserType::class, $user, [
            'is_edit' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Gestion du mot de passe
            $plainPassword = $form->get('plainPassword')->getData();
            if (!empty($plainPassword)) {
                $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);
            }

            // Enregistrement des modifications
            $entityManager->flush();

            $this->addFlash('success', 'Profil mis à jour avec succès!');
            return $this->redirectToRoute('app_user_profile', ['id' => $user->getId()]);
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/role', name: 'app_user_role_edit', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function editRole(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        $newRoles = $request->request->get('roles', 'ROLE_USER');
        if (!is_array($newRoles)) {
            $newRoles = [$newRoles];
        }

        $user->setRoles($newRoles);
        $entityManager->flush();

        $this->addFlash('success', 'Les rôles de l\'utilisateur ont été mis à jour.');

        return $this->redirectToRoute('app_user_show', ['id' => $user->getId()]);
    }

    #[Route('/{id}', name: 'app_user_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            $entityManager->remove($user);
            $entityManager->flush();

            $this->addFlash('success', 'Utilisateur supprimé avec succès.');
        }

        return $this->redirectToRoute('app_user_index');
    }
}