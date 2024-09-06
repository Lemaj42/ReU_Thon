<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Meeting;
use App\Entity\User;
use App\Entity\Vote;
use App\Form\MeetingType;
use App\Repository\BookingRepository;
use App\Repository\UserRepository;
use App\Repository\MeetingRepository;
use App\Repository\VoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
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

        // Définir une valeur par défaut pour finalDate
        $meeting->setFinalDate(new \DateTime()); // Ou toute autre valeur par défaut appropriée

        $form = $this->createForm(MeetingType::class, $meeting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Trier les bookings par date
            $bookings = $meeting->getBookings()->toArray();
            usort($bookings, function (Booking $a, Booking $b) {
                return $a->getDate() <=> $b->getDate();
            });

            // Définir la date de fin de vote (7 jours avant la première date proposée)
            $firstBookingDate = $bookings[0]->getDate();
            $votingDeadline = (clone $firstBookingDate)->modify('-7 days');
            $meeting->setVotingDeadline($votingDeadline);

            foreach ($bookings as $booking) {
                $booking->setMeeting($meeting);
                $entityManager->persist($booking);
            }

            $entityManager->persist($meeting);
            $entityManager->flush();

            // Envoi des e-mails aux utilisateurs
            $users = $userRepository->findAll();
            foreach ($users as $recipient) {
                $arrayBookingUrl = [];
                foreach ($bookings as $booking) {
                    $url = $urlGenerator->generate('app_booking_vote_email', [
                        'bookingId' => $booking->getId(),
                        'userId' => $recipient->getId(),
                    ], UrlGeneratorInterface::ABSOLUTE_URL);
                    array_push($arrayBookingUrl, ['url' => $url, 'date' => $booking->getDate()]);
                }

                $email = (new TemplatedEmail())
                    ->from('fabien@example.com')
                    ->to($recipient->getEmail())
                    ->subject('Nouvelle réunion : ' . $meeting->getTitle())
                    ->htmlTemplate('emails/vote_email.html.twig')
                    ->context([
                        'meeting' => $meeting,
                        'arrayBookingUrl' => $arrayBookingUrl,
                        'user' => $recipient,
                        'votingDeadline' => $votingDeadline
                    ]);

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

    // Nouvelle méthode pour finaliser la date de la réunion
    #[Route('/{id}/finalize', name: 'app_meeting_finalize', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function finalizeMeetingDate(Meeting $meeting, EntityManagerInterface $entityManager, VoteRepository $voteRepository): Response
    {
        $now = new \DateTime();
        if ($now < $meeting->getVotingDeadline()) {
            $this->addFlash('error', 'La période de vote n\'est pas encore terminée.');
            return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
        }

        $bookings = $meeting->getBookings();
        $mostVotedBooking = null;
        $maxVotes = -1;

        foreach ($bookings as $booking) {
            $voteCount = $voteRepository->count(['booking' => $booking]);
            if ($voteCount > $maxVotes) {
                $maxVotes = $voteCount;
                $mostVotedBooking = $booking;
            }
        }

        if ($mostVotedBooking) {
            $meeting->setFinalDate($mostVotedBooking->getDate());
            $entityManager->flush();
            $this->addFlash('success', 'La date finale de la réunion a été déterminée.');
        } else {
            $this->addFlash('error', 'Aucune date n\'a été choisie. Veuillez sélectionner une date manuellement.');
        }

        return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
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

        $entityManager->remove($meeting);
        $entityManager->flush();

        $this->addFlash('success', 'Réunion supprimée avec succès.');

        return $this->redirectToRoute('app_meeting_index');

    }

    #[Route('/{id}', name: 'app_meeting_show', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function show(Meeting $meeting, VoteRepository $voteRepository): Response
    {
        $user = $this->getUser();
        $vote = $voteRepository->findBy([
            'user' => $user,
            'booking' => $meeting->getBookings()->toArray()
        ]);

        $hasVoted = !empty($vote);

        $googleMapsApiKey = $_ENV['GOOGLE_MAPS_API_KEY'] ?? null;

        return $this->render('meeting/show.html.twig', [
            'meeting' => $meeting,
            'hasVoted' => $hasVoted,
            'vote' => $hasVoted ? $vote[0] : null,
            'google_maps_api_key' => $googleMapsApiKey,
        ]);
    }
    // Vote 
    #[Route('/{id}/vote', name: 'app_meeting_vote', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function vote(Meeting $meeting, Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $dateChosen = new \DateTime($request->request->get('date'));

        // Recherche de la réservation existante
        $existingBooking = $entityManager->getRepository(Booking::class)->findOneBy([
            'meeting' => $meeting,
            'date' => $dateChosen
        ]);

        if (!$existingBooking) {
            $this->addFlash('error', 'Aucune réservation trouvée pour cette date et ce meeting.');
            return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
        }

        // Vérifie si un vote existe déjà pour cet utilisateur et cette réservation
        $existingVote = $entityManager->getRepository(Vote::class)->findOneBy([
            'user' => $user,
            'booking' => $existingBooking,
        ]);

        if (!$existingVote) {
            // Crée un nouveau vote pour la réservation existante
            $vote = new Vote();
            $vote->setUser($user);
            $vote->setBooking($existingBooking);
            $entityManager->persist($vote);
            $entityManager->flush();

            $this->addFlash('success', "Votre vote a été enregistré.");
        } else {
            $this->addFlash('warning', 'Vous avez déjà voté pour cette réunion.');
        }

        return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
    }
}