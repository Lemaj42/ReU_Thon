<?php

namespace App\Controller;

use App\Entity\Meeting;
use App\Form\MeetingType;
use App\Repository\UserRepository;
use App\Repository\MeetingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/meeting')]
class MeetingController extends AbstractController
{
    // Affiche la Liste des Meetings 
    #[Route('/', name: 'app_meeting_index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')] // Seuls les utilisateurs authentifiés peuvent voir la Liste des Meetings 
    public function index(MeetingRepository $meetingRepository): Response
    {
        $meetings = $meetingRepository->findAll(); // Récupère tous les meetings
        return $this->render('meeting/index.html.twig', [
            'meetings' => $meetings,
        ]);
    }

    // Crée un nouveau meeting
    #[Route('/meeting/new', name: 'app_meeting_new', methods: ['GET', 'POST'])]

    public function new(Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer, UserRepository $userRepository, UrlGeneratorInterface $urlGenerator): Response
    {
        $meeting = new Meeting();
        $user = $this->getUser();
        $meeting->setOwner($user);

        $form = $this->createForm(MeetingType::class, $meeting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($meeting);
            $entityManager->flush();

            // Envoi des e-mails aux utilisateurs
            $users = $userRepository->findAll();
            foreach ($users as $recipient) {
                // Génère les URL pour voter via l'e-mail
                $voteForMainDateUrl = $urlGenerator->generate('app_booking_vote_email', [
                    'meetingId' => $meeting->getId(),
                    'userId' => $recipient->getId(),
                    'date' => $meeting->getDate()->format('Y-m-d H:i:s'),
                ], UrlGeneratorInterface::ABSOLUTE_URL);

                $voteForAltDateUrl = $urlGenerator->generate('app_booking_vote_email', [
                    'meetingId' => $meeting->getId(),
                    'userId' => $recipient->getId(),
                    'date' => $meeting->getAlternativeDate()->format('Y-m-d H:i:s'),
                ], UrlGeneratorInterface::ABSOLUTE_URL);

                // Création de l'e-mail avec les liens de vote
                $email = (new Email())
                    ->from('noreply@example.com')
                    ->to($recipient->getEmail())
                    ->subject('Nouvelle réunion : ' . $meeting->getTitle())
                    ->html(sprintf(
                        'Bonjour %s,<br><br>Une nouvelle réunion a été programmée : %s à %s.<br><br>
                    Veuillez choisir votre date préférée en cliquant sur l\'un des liens ci-dessous :<br>
                    <a href="%s">Choisir la date principale</a> ou <a href="%s">Choisir la date alternative</a>.<br><br>
                    Merci !',
                        $recipient->getFirstname(),
                        $meeting->getTitle(),
                        $meeting->getPlace(),
                        $voteForMainDateUrl,
                        $voteForAltDateUrl
                    ));

                $mailer->send($email);
            }

            $this->addFlash('success', 'Réunion créée avec succès ! Les utilisateurs ont été notifiés par email.');
            return $this->redirectToRoute('app_meeting_index');
        }

        return $this->render('meeting/new.html.twig', [
            'meeting' => $meeting,
            'form' => $form->createView(),
        ]);
    }

    // Affiche les détails d'un meeting
    #[Route('/{id}', name: 'app_meeting_show', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')] // Seuls les utilisateurs authentifiés peuvent voir un meeting
    public function show(Meeting $meeting): Response
    {
        // Passe le meeting à la vue pour affichage des détails (incluant la Google Maps avec l'adresse)
        return $this->render('meeting/show.html.twig', [
            'meeting' => $meeting,
        ]);
    }

    // Modifie un meeting existant
    #[Route('/{id}/edit', name: 'app_meeting_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')] // Seuls les administrateurs peuvent modifier un meeting
    public function edit(Request $request, Meeting $meeting, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(MeetingType::class, $meeting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Mise à jour du meeting dans la base de données
            $entityManager->flush();

            // Message de succès et redirection
            $this->addFlash('success', 'Réunion mise à jour avec succès!');
            return $this->redirectToRoute('app_meeting_index', [], Response::HTTP_SEE_OTHER);
        }

        // Affiche le formulaire d'édition
        return $this->render('meeting/edit.html.twig', [
            'meeting' => $meeting,
            'form' => $form->createView(),
        ]);
    }

    // Supprime un meeting existant
    #[Route('/{id}', name: 'app_meeting_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')] // Seuls les administrateurs peuvent supprimer un meeting
    public function delete(Request $request, Meeting $meeting, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $meeting->getId(), $request->request->get('_token'))) {
            // Suppression du meeting de la base de données
            $entityManager->remove($meeting);
            $entityManager->flush();

            // Message de succès
            $this->addFlash('success', 'Réunion supprimée avec succès.');
        }

        return $this->redirectToRoute('app_meeting_index', [], Response::HTTP_SEE_OTHER);
    }
}
