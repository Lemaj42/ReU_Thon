<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Meeting;
use App\Entity\User;
use App\Entity\Vote;
use App\Form\BookingType;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/booking')]
#[IsGranted('ROLE_USER')]
class BookingController extends AbstractController
{
    #[Route('/', name: 'app_booking_index', methods: ['GET'])]
    public function index(BookingRepository $bookingRepository): Response
    {
        return $this->render('booking/index.html.twig', [
            'bookings' => $bookingRepository->findAll(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_booking_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, Booking $booking, EntityManagerInterface $entityManager): Response
    {
        $originalMeeting = $booking->getMeeting(); // Stockez le Meeting original

        $form = $this->createForm(BookingType::class, $booking);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$booking->getMeeting()) {
                $booking->setMeeting($originalMeeting); // Réassignez le Meeting original si aucun n'a été sélectionné
            }
            $entityManager->flush();

            return $this->redirectToRoute('app_booking_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('booking/edit.html.twig', [
            'booking' => $booking,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_booking_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Booking $booking, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $booking->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($booking);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_booking_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/vote-email', name: 'app_booking_vote_email', methods: ['GET'])]
    public function voteByEmail(EntityManagerInterface $entityManager, Request $request): Response
    {
        $bookingId = $request->query->get('bookingId');
        $userId = $request->query->get('userId');
        $booking = $entityManager->getRepository(Booking::class)->find($bookingId);
        $user = $entityManager->getRepository(User::class)->find($userId);

        if (!$booking || !$user) {
            throw $this->createNotFoundException('Le meeting ou l\'utilisateur n\'existe pas.');
        }

        // Vérifie si l'utilisateur a déjà voté pour ce meeting
        $existingVote = $entityManager->getRepository(Vote::class)->findOneBy([
            'user' => $user,
            'booking' => $booking,
        ]);

        if (!$existingVote) {
            $vote = new Vote();
            $vote->setUser($user);
            $vote->setBooking($booking);
            $entityManager->persist($vote);
        }

        $entityManager->flush();

        // Message flash pour confirmation
        $this->addFlash('success', 'Votre vote a été enregistré.');

        // Redirige vers la page de confirmation ou autre
        return $this->redirectToRoute('app_meeting_show', ['id' => $booking->getMeeting()->getId()]);
    }
}