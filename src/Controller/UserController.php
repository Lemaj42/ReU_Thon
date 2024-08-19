<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Form\RegisterFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/user')]
class UserController extends AbstractController
{
    #[Route('/', name: 'app_user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }

    
    // Pas besoin de cette route le /register est déjà géré par le RegistrationController

    // #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    // public function new(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    // {
    //     $user = new User();
    //     $form = $this->createForm(RegisterFormType::class, $user);
    //     $form->handleRequest($request);

    //     if ($form->isSubmitted() && $form->isValid()) {
    //         // Récupérer le mot de passe en clair depuis le formulaire
    //         $plainPassword = $form->get('plainPassword')->getData();
    //         if ($plainPassword) {
    //             $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
    //             $user->setPassword($hashedPassword);
    //         }

    //         // Attribuer un rôle par défaut
    //         $user->setRoles(['ROLE_USER']);

    //         // Sauvegarde de l'utilisateur en base de données
    //         $entityManager->persist($user);
    //         $entityManager->flush();

    //         $this->addFlash('success', 'Compte créé avec succès! Bienvenue ' . $user->getFirstname());

    //         // Redirection vers la page des réunions après création de compte
    //         return $this->redirectToRoute('app_meeting_index');
    //     }

    //     return $this->render('user/new.html.twig', [
    //         'form' => $form->createView(),
    //     ]);
    // }

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
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        if ($user !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à modifier ce profil.');
        }

        $form = $this->createForm(UserType::class, $user, [
            'roles_disabled' => !$this->isGranted('ROLE_ADMIN'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Vérifier si le mot de passe a été changé
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);
            }

            $entityManager->flush();

            $this->addFlash('success', 'Profil mis à jour avec succès!');

            // Redirection vers la page des réunions après modification du profil
            return $this->redirectToRoute('app_meeting_index');
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
        $newRoles = $request->request->get('roles', ['ROLE_USER']);
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
