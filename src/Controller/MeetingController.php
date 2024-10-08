<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Meeting;
use App\Entity\User;
use App\Entity\Vote;
use App\Form\MeetingType;
use App\Repository\UserRepository;
use App\Repository\VoteRepository;
use App\Repository\MeetingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/meeting')]
class MeetingController extends AbstractController
{
    // src/Controller/MeetingController.php
    #[Route('/', name: 'app_meeting_index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(MeetingRepository $meetingRepository): Response
    {
        $now = new \DateTime();
        $meetings = $meetingRepository->createQueryBuilder('m')
            ->where('m.votingDeadline > :now OR (m.finalDate IS NOT NULL AND m.finalDate > :now)')
            ->setParameter('now', $now)
            ->orderBy('m.votingDeadline', 'ASC')
            ->getQuery()
            ->getResult();

        // Récupération de la clé Google Maps depuis les paramètres
        $googleMapsApiKey = $this->getParameter('google_maps_api_key');

        return $this->render('meeting/index.html.twig', [
            'meetings' => $meetings,
            'google_maps_api_key' => $googleMapsApiKey,
            'GOOGLE_MAPS_API_KEY' => $googleMapsApiKey, // Ajoutez cette ligne
        ]);
    }

    #[Route('/archive', name: 'app_meeting_archive', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function archive(MeetingRepository $meetingRepository): Response
    {
        $now = new \DateTime();
        $archivedMeetings = $meetingRepository->createQueryBuilder('m')
            ->where('m.finalDate IS NOT NULL')
            ->andWhere('m.finalDate < :now')
            ->setParameter('now', $now)
            ->orderBy('m.finalDate', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('meeting/archive.html.twig', [
            'archivedMeetings' => $archivedMeetings,
        ]);
    }

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
        $meeting->setOwner($user);  // Le propriétaire de la réunion est l'utilisateur actuel

        // Création du formulaire pour la réunion
        $form = $this->createForm(MeetingType::class, $meeting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $bookings = $meeting->getBookings()->toArray();

            // Vérification s'il y a des bookings associés
            if (empty($bookings)) {
                $this->addFlash('error', 'Aucun booking n\'a été ajouté à la réunion.');
                return $this->render('meeting/new.html.twig', [
                    'meeting' => $meeting,
                    'form' => $form->createView(),
                ]);
            }
            $firstBookingDate = $bookings[0]->getDate();
            $votingDeadline = (clone $firstBookingDate)->modify('-7 days');
            $meeting->setVotingDeadline($votingDeadline);

            // Persistons les bookings et la réunion
            foreach ($bookings as $booking) {
                $booking->setMeeting($meeting);  // Associe chaque booking à la réunion
                $entityManager->persist($booking);
            }

            // Persistons la réunion
            $entityManager->persist($meeting);
            $entityManager->flush();

            // Envoi d'e-mails aux utilisateurs après la création de la réunion
            $users = $userRepository->findAll();
            foreach ($users as $recipient) {
                $arrayBookingUrl = [];
                foreach ($bookings as $booking) {
                    // Génération d'un lien pour chaque booking pour permettre aux utilisateurs de voter
                    $url = $urlGenerator->generate('app_booking_vote_email', [
                        'bookingId' => $booking->getId(),
                        'userId' => $recipient->getId(),
                    ], UrlGeneratorInterface::ABSOLUTE_URL);
                    array_push($arrayBookingUrl, ['url' => $url, 'date' => $booking->getDate()]);
                }

                // Création de l'e-mail avec Twig
                $email = (new TemplatedEmail())
                    ->from('fabien@example.com')
                    ->to($recipient->getEmail())
                    ->subject('Nouvelle réunion : ' . $meeting->getTitle())
                    ->htmlTemplate('emails/vote_email.html.twig')
                    ->context([
                        'meeting' => $meeting,
                        'arrayBookingUrl' => $arrayBookingUrl,
                        'user' => $recipient,
                        'votingDeadline' => $meeting->getVotingDeadline() // Assurez-vous de bien passer votingDeadline
                    ]);

                $mailer->send($email);
            }

            // Ajout d'un message flash et redirection
            $this->addFlash('success', 'Réunion créée avec succès ! Les utilisateurs ont été notifiés par email.');
            return $this->redirectToRoute('app_meeting_index');
        }

        // Retourne le formulaire pour créer la réunion
        return $this->render('meeting/new.html.twig', [
            'meeting' => $meeting,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/close-votes/{id}', name: 'app_meeting_close_votes', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function closeVotes(
        Meeting $meeting,
        EntityManagerInterface $entityManager,
        VoteRepository $voteRepository
    ): Response {
        // Vérifier si la date finale n'a pas déjà été définie
        if ($meeting->getFinalDate() !== null) {
            $this->addFlash('warning', 'Les votes ont déjà été clôturés pour cette réunion.');
            return $this->redirectToRoute('app_meeting_index');
        }

        // Récupérer tous les bookings associés à cette réunion
        $bookings = $meeting->getBookings()->toArray();

        if (empty($bookings)) {
            $this->addFlash('error', 'Aucun booking n\'a été associé à cette réunion.');
            return $this->redirectToRoute('app_meeting_index');
        }

        // Compter les votes pour chaque booking
        $votesCount = [];
        foreach ($bookings as $booking) {
            $votesCount[$booking->getId()] = count($voteRepository->findBy(['booking' => $booking]));
        }

        // Trouver le booking avec le plus de votes
        $mostVotedBooking = null;
        $maxVotes = -1;
        foreach ($bookings as $booking) {
            $bookingId = $booking->getId();
            if ($votesCount[$bookingId] > $maxVotes) {
                $mostVotedBooking = $booking;
                $maxVotes = $votesCount[$bookingId];
            }
        }

        // Vérification si un booking a bien été trouvé
        if ($mostVotedBooking === null) {
            $this->addFlash('error', 'Aucun booking n\'a reçu de vote.');
            return $this->redirectToRoute('app_meeting_index');
        }

        // Définir la date finale comme la date du booking ayant reçu le plus de votes
        $finalDate = $mostVotedBooking->getDate();
        if ($finalDate instanceof \DateTimeInterface) {
            $meeting->setFinalDate($finalDate);

            // Persister la réunion avec la date finale
            $entityManager->persist($meeting);
            $entityManager->flush();

            $this->addFlash('success', 'Les votes sont clôturés et la date finale de la réunion est définie.');
        } else {
            $this->addFlash('error', 'La date du booking sélectionné n\'est pas valide.');
        }

        return $this->redirectToRoute('app_meeting_index');
    }

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

    #[Route('/{id}', name: 'app_meeting_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Meeting $meeting, EntityManagerInterface $entityManager): Response
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

    #[Route('/{id}/vote', name: 'app_meeting_vote', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function vote(Meeting $meeting, Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $dateChosen = new \DateTime($request->request->get('date'));

        $existingBooking = $entityManager->getRepository(Booking::class)->findOneBy([
            'meeting' => $meeting,
            'date' => $dateChosen
        ]);

        if (!$existingBooking) {
            $this->addFlash('error', 'Aucune réservation trouvée pour cette date et ce meeting.');
            return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
        }

        $existingVote = $entityManager->getRepository(Vote::class)->findOneBy([
            'user' => $user,
            'booking' => $existingBooking,
        ]);

        if (!$existingVote) {
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