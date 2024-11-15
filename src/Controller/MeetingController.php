<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Meeting;
use App\Entity\Vote;
use App\Form\MeetingType;
use App\Repository\UserRepository;
use App\Repository\VoteRepository;
use App\Repository\MeetingRepository;
use Doctrine\Common\Collections\ArrayCollection;
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

        // Suppression de la récupération et de l'envoi de la clé Google Maps

        return $this->render('meeting/index.html.twig', [
            'meetings' => $meetings,
        ]);
    }


    #[Route('/archive', name: 'app_meeting_archive', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function archive(MeetingRepository $meetingRepository): Response
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Paris'));
        $now->format('Y-m-d H:i:s'); // Format précis à la seconde

        $archivedMeetings = $meetingRepository->createQueryBuilder('m')
            ->where('m.finalDate IS NOT NULL')
            ->andWhere('m.finalDate <= :now')
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

            // Tri des bookings par date (si non trié dans l'entité) pour s'assurer de prendre la première date
            usort($bookings, fn($a, $b) => $a->getDate() <=> $b->getDate());
            $firstBookingDate = $bookings[0]->getDate();

            // Calcul de la date de fin de vote (7 jours avant la première date de booking)
            if ($firstBookingDate) {
                $votingDeadline = (clone $firstBookingDate)->modify('-7 days');
                $meeting->setVotingDeadline($votingDeadline);
            } else {
                $this->addFlash('error', 'La date du premier booking est invalide.');
                return $this->render('meeting/new.html.twig', [
                    'meeting' => $meeting,
                    'form' => $form->createView(),
                ]);
            }

            // Persistons les bookings et la réunion
            foreach ($meeting->getBookings() as $booking) {
                $booking->setMeeting($meeting);  // Associe chaque booking à la réunion
                if ($booking->getDate() === null) {
                    $this->addFlash('error', 'Tous les bookings doivent avoir une date valide.');
                    return $this->render('meeting/new.html.twig', [
                        'meeting' => $meeting,
                        'form' => $form->createView(),
                    ]);
                }
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

    #[Route('/calculate-final-date/{id}', name: 'app_meeting_calculate_final_date', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function calculateFinalDate(
        Meeting $meeting,
        EntityManagerInterface $entityManager,
        VoteRepository $voteRepository
    ): Response {
        // Vérifier si la date finale est déjà définie
        if ($meeting->getFinalDate() !== null) {
            $this->addFlash('warning', 'La date finale est déjà définie pour cette réunion.');
            return $this->redirectToRoute('app_meeting_index');
        }

        // Récupérer la date limite de vote
        $votingDeadline = $meeting->getVotingDeadline();

        // Récupérer tous les bookings associés à cette réunion
        $bookings = $meeting->getBookings()->toArray();

        if (empty($bookings)) {
            $this->addFlash('error', 'Aucun booking n\'est associé à cette réunion.');
            return $this->redirectToRoute('app_meeting_index');
        }

        // Compter les votes pour chaque date de booking avant la date limite
        $votesCount = [];
        foreach ($bookings as $booking) {
            $votes = $voteRepository->findBy(['booking' => $booking]);

            // Filtrer les votes valides (avant la date limite)
            $validVotes = array_filter($votes, function ($vote) use ($votingDeadline) {
                return $vote->getCreatedAt() <= $votingDeadline;
            });

            $votesCount[$booking->getId()] = count($validVotes);
        }

        // Sélectionner le booking avec le plus de votes
        $mostVotedBooking = null;
        $maxVotes = -1;
        foreach ($bookings as $booking) {
            $bookingId = $booking->getId();
            if ($votesCount[$bookingId] > $maxVotes) {
                $mostVotedBooking = $booking;
                $maxVotes = $votesCount[$bookingId];
            }
        }

        // Si aucun vote valide n'existe, utiliser la date de fin de vote
        if ($maxVotes === 0) {
            $finalDate = $votingDeadline;
        } else {
            $finalDate = $mostVotedBooking->getDate();
        }

        // Définir la date finale et sauvegarder
        if ($finalDate instanceof \DateTimeInterface) {
            $meeting->setFinalDate($finalDate);
            $entityManager->persist($meeting);
            $entityManager->flush();

            $this->addFlash('success', 'La date finale de la réunion a été calculée et définie automatiquement.');
        } else {
            $this->addFlash('error', 'La date sélectionnée n\'est pas valide.');
        }

        return $this->redirectToRoute('app_meeting_index');
    }

    #[Route('/{id}/vote', name: 'app_meeting_vote_action', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function voteAction(
        Meeting $meeting,
        Request $request,
        EntityManagerInterface $entityManager,
        VoteRepository $voteRepository
    ): Response {
        $user = $this->getUser();

        // Vérification de la date limite de vote définie automatiquement
        $votingDeadline = $meeting->getVotingDeadline();
        if ($votingDeadline && new \DateTime() > $votingDeadline) {
            $this->addFlash('error', 'La période de vote est clôturée car la date limite est dépassée.');
            return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
        }

        // Récupération de la date choisie pour le vote
        $dateChosen = new \DateTime($request->request->get('date'));

        // Recherche du booking correspondant à la date choisie
        $existingBooking = $entityManager->getRepository(Booking::class)->findOneBy([
            'meeting' => $meeting,
            'date' => $dateChosen
        ]);

        if (!$existingBooking) {
            $this->addFlash('error', 'Aucune réservation trouvée pour cette date et ce meeting.');
            return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
        }

        // Vérification si l'utilisateur a déjà voté
        $existingVote = $voteRepository->findOneBy([
            'user' => $user,
            'booking' => $existingBooking,
        ]);

        if (!$existingVote) {
            // Création du vote si l'utilisateur n'a pas encore voté
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

    #[Route('/{id}/edit', name: 'app_meeting_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, Meeting $meeting, EntityManagerInterface $entityManager): Response
    {
        $originalBookings = new ArrayCollection();
        foreach ($meeting->getBookings() as $booking) {
            $originalBookings->add($booking);
        }

        $form = $this->createForm(MeetingType::class, $meeting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Supprimez les bookings qui ont été retirés
            foreach ($originalBookings as $booking) {
                if (false === $meeting->getBookings()->contains($booking)) {
                    $booking->setMeeting(null);
                    $entityManager->remove($booking);
                }
            }

            // Assurez-vous que chaque booking a une référence à ce meeting
            foreach ($meeting->getBookings() as $booking) {
                $booking->setMeeting($meeting);
                if ($booking->getDate() === null) {
                    $this->addFlash('error', 'Tous les bookings doivent avoir une date valide.');
                    return $this->render('meeting/edit.html.twig', [
                        'meeting' => $meeting,
                        'form' => $form->createView(),
                    ]);
                }
            }

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

        return $this->render('meeting/show.html.twig', [
            'meeting' => $meeting,
            'hasVoted' => $hasVoted,
            'vote' => $hasVoted ? $vote[0] : null,
        ]);
    }



}