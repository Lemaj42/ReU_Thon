<?php

namespace App\Entity;

use App\Repository\BookingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\UX\Turbo\Attribute\Broadcast;

#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[Broadcast(entity: "booking")] // Diffuse l'entité `Booking`
class Booking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $answer = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Meeting $meeting = null;

    // Nouvelle propriété pour stocker la date choisie
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $chosenDate = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnswer(): ?string
    {
        return $this->answer;
    }

    public function setAnswer(string $answer): static
    {
        $this->answer = $answer;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getMeeting(): ?Meeting
    {
        return $this->meeting;
    }

    public function setMeeting(?Meeting $meeting): static
    {
        $this->meeting = $meeting;

        return $this;
    }

    // Nouvelle méthode getChosenDate()
    public function getChosenDate(): ?string
    {
        return $this->chosenDate;
    }

    // Nouvelle méthode setChosenDate()
    public function setChosenDate(?string $chosenDate): static
    {
        $this->chosenDate = $chosenDate;

        return $this;
    }
}
