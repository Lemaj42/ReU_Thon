<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Meeting;
use App\Form\MeetingType;
use App\Repository\BookingRepository;
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
    // Affiche la liste des réunions
    #[Route('/', name: 'app_meeting_index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(MeetingRepository $meetingRepository): Response
    {
        $meetings = $meetingRepository->findAll();
        return $this->render('meeting/index.html.twig', [
            'meetings' => $meetings,
        ]);
    }

    // Crée une nouvelle réunion
    #[Route('/new', name: 'app_meeting_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        UserRepository $userRepository,
        UrlGeneratorInterface $urlGenerator
    ): Response {
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

    // Affiche les détails d'une réunion
    #[Route('/{id}', name: 'app_meeting_show', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function show(Meeting $meeting, BookingRepository $bookingRepository): Response
    {
        $user = $this->getUser();
        $booking = $bookingRepository->findOneBy([
            'user' => $user,
            'meeting' => $meeting,
        ]);

        $hasVoted = $booking !== null;
        $chosenDate = $hasVoted ? $booking->getChosenDate() : null;

        $googleMapsApiKey = $_ENV['GOOGLE_MAPS_API_KEY'];

        return $this->render('meeting/show.html.twig', [
            'meeting' => $meeting,
            'hasVoted' => $hasVoted,
            'chosenDate' => $chosenDate,
            'google_maps_api_key' => $googleMapsApiKey,
        ]);
    }



    // Modifie une réunion existante
    #[Route('/{id}/edit', name: 'app_meeting_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, Meeting $meeting, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(MeetingType::class, $meeting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Réunion mise à jour avec succès!');
            return $this->redirectToRoute('app_meeting_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('meeting/edit.html.twig', [
            'meeting' => $meeting,
            'form' => $form->createView(),
        ]);
    }

    // Supprime une réunion existante
    #[Route('/{id}', name: 'app_meeting_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Meeting $meeting, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $meeting->getId(), $request->request->get('_token'))) {
            $entityManager->remove($meeting);
            $entityManager->flush();
            $this->addFlash('success', 'Réunion supprimée avec succès.');
        }

        return $this->redirectToRoute('app_meeting_index', [], Response::HTTP_SEE_OTHER);
    }

    // Vote 
    #[Route('/{id}/vote', name: 'app_meeting_vote', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function vote(Meeting $meeting, Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $dateChosen = $request->request->get('date'); // La date est récupérée comme une chaîne de caractères

        // Vérif voté
        $existingBooking = $entityManager->getRepository(Booking::class)->findOneBy([
            'user' => $user,
            'meeting' => $meeting,
        ]);

        if ($existingBooking) {
            $chosenDate = $existingBooking->getChosenDate(); // Récupère la date sous forme de chaîne de caractères
            $this->addFlash('warning', sprintf('Vous avez déjà voté pour cette réunion. Vous avez choisi la date : %s.', (new \DateTime($chosenDate))->format('d/m/Y H:i')));
            return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
        }

        // Crée une nouvelle réservation pour l'utilisateur
        $booking = new Booking();
        $booking->setUser($user);
        $booking->setMeeting($meeting);
        $booking->setChosenDate($dateChosen); // Passe directement la chaîne de caractères
        $booking->setAnswer('Choix de la date: ' . $dateChosen);

        $entityManager->persist($booking);
        $entityManager->flush();

        $this->addFlash('success', sprintf("Vous avez choisi la date : %s.", (new \DateTime($dateChosen))->format('d/m/Y H:i')));
        return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
    }
}