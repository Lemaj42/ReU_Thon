<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Meeting;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BookingController extends AbstractController
{
    #[Route('/booking', name: 'app_booking_vote_email', methods: ['GET'])]
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