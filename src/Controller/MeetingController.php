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

        $form = $this->createForm(MeetingType::class, $meeting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $meeting->getBookings()->map(function (Booking $booking) use ($entityManager, $meeting) {
                $booking->setMeeting($meeting);
                $entityManager->persist($booking);
            });
            $entityManager->persist($meeting);
            $entityManager->flush();

            // Envoi des e-mails aux utilisateurs
            $users = $userRepository->findAll();
            foreach ($users as $recipient) {
                $arrayBookingUrl = [];
                $bookings = $meeting->getBookings()->getValues();

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

                    // path of the Twig template to render
                    ->htmlTemplate('emails/vote_email.html.twig')
                    ->context([
                        'meeting' => $meeting,
                        'arrayBookingUrl' => $arrayBookingUrl,
                        'user' => $recipient
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

    // Affiche les détails d'une réunion
    #[Route('/{id}', name: 'app_meeting_show', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function show(Meeting $meeting, VoteRepository $voteRepository): Response
    {
        /**
         * @var User $user
         */
        $user = $this->getUser();


        $votes = $voteRepository->findBy(["user" => $user]);
        $vote = array_filter($votes, function (Vote $vote) use ($meeting) {
            return $vote->getBooking()->getMeeting()->getId() === $meeting->getId();
        });

        $hasVoted = count($vote) > 0;

        $googleMapsApiKey = $_ENV['GOOGLE_MAPS_API_KEY'];

        return $this->render('meeting/show.html.twig', [
            'meeting' => $meeting,
            'hasVoted' => $hasVoted,
            'vote' => count($vote) > 0 ? $vote[0] : null,
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

    // // Vote 
    // #[Route('/{id}/vote', name: 'app_meeting_vote', methods: ['POST'])]
    // #[IsGranted('IS_AUTHENTICATED_FULLY')]
    // public function vote(Meeting $meeting, Request $request, EntityManagerInterface $entityManager): Response
    // {
    //     $user = $this->getUser();
    //     $dateChosen = $request->request->get('date'); // La date est récupérée comme une chaîne de caractères

    //     // Vérif voté
    //     $existingBooking = $entityManager->getRepository(Booking::class)->findOneBy([
    //         'user' => $user,
    //         'meeting' => $meeting,
    //     ]);

    //     if ($existingBooking) {
    //         $chosenDate = $existingBooking->getChosenDate(); // Récupère la date sous forme de chaîne de caractères
    //         $this->addFlash('warning', sprintf('Vous avez déjà voté pour cette réunion. Vous avez choisi la date : %s.', (new \DateTime($chosenDate))->format('d/m/Y H:i')));
    //         return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
    //     }

    //     // Crée une nouvelle réservation pour l'utilisateur
    //     $booking = new Booking();
    //     $booking->setMeeting($meeting);
    //     $booking->setAnswer('Choix de la date: ' . $dateChosen);

    //     $entityManager->persist($booking);
    //     $entityManager->flush();

    //     $this->addFlash('success', sprintf("Vous avez choisi la date : %s.", (new \DateTime($dateChosen))->format('d/m/Y H:i')));
    //     return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
    // }
}