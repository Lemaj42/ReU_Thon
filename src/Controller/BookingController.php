<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Meeting;
use App\Entity\User;
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

    #[Route('/new', name: 'app_booking_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $booking = new Booking();
        $form = $this->createForm(BookingType::class, $booking);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($booking);
            $entityManager->flush();

            return $this->redirectToRoute('app_booking_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('booking/new.html.twig', [
            'booking' => $booking,
            'form' => $form,
        ]);
    }

    // #[Route('/{id}', name: 'app_booking_show', methods: ['GET'])]
    // public function show($id, EntityManagerInterface $entityManager, Request $request): Response
    // {
    //     if (!$this->isGranted('IS_AUTHENTICATED_FULLY')) {
    //         // L'utilisateur n'est pas connecté, on le redirige vers la page de connexion
    //         return new RedirectResponse($this->generateUrl('app_login'));
    //     }
    //     $meetingId = $request->query->get('meetingId');
    //     $booking = $entityManager->getRepository(Booking::class)->find($meetingId);

    //     if (!$booking) {
    //         throw $this->createNotFoundException('La réservation demandée n\'existe pas.');
    //     }

    //     // L'utilisateur est connecté et la réservation existe, on affiche la page
    //     return $this->render('booking/show.html.twig', [
    //         'booking' => $booking,
    //         'meetingId' => $meetingId,
    //     ]);
    // }


    #[Route('/{id}/edit', name: 'app_booking_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, Booking $booking, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(BookingType::class, $booking);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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
        $meetingId = $request->query->get('meetingId');
        $userId = $request->query->get('userId');
        $date = $request->query->get('date');

        $meeting = $entityManager->getRepository(Meeting::class)->find($meetingId);
        $user = $entityManager->getRepository(User::class)->find($userId);

        if (!$meeting || !$user) {
            throw $this->createNotFoundException('Le meeting ou l\'utilisateur n\'existe pas.');
        }

        // Vérifie si l'utilisateur a déjà voté pour ce meeting
        $existingBooking = $entityManager->getRepository(Booking::class)->findOneBy([
            'user' => $user,
            'meeting' => $meeting,
        ]);

        if (!$existingBooking) {
            $booking = new Booking();
            $booking->setUser($user);
            $booking->setMeeting($meeting);
            $booking->setChosenDate($date);

            // Assurez-vous que 'answer' est défini
            $booking->setAnswer('Vote enregistré');  // Exemple de valeur par défaut

            $entityManager->persist($booking);
        }

        $entityManager->flush();

        // Message flash pour confirmation
        $this->addFlash('success', 'Votre vote a été enregistré.');

        // Redirige vers la page de confirmation ou autre
        return $this->redirectToRoute('app_meeting_show', ['id' => $meetingId]);
    }
}