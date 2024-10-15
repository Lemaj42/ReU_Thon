<?php
// src/Controller/ContactController.php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class ContactController extends AbstractController
{
    private $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    #[Route('/contact/send', name: 'contact_send', methods: ['POST'])]
    public function send(Request $request, MailerInterface $mailer): Response
    {
        $title = $request->request->get('title');
        $name = $request->request->get('name');
        $email = $request->request->get('email');
        $messageContent = $request->request->get('message');

        if (!$title || !$name || !$email || !$messageContent) {
            return $this->redirectToRoute('app_user_contact'); // Redirection vers la route correcte
        }

        // Utiliser Twig pour rendre le template d'email
        $emailBody = $this->twig->render('emails/contact_email.html.twig', [
            'title' => $title,
            'name' => $name,
            'email' => $email,
            'message' => $messageContent,
        ]);

        $email = (new Email())
            ->from($email)
            ->to('colette@reucap.fr')
            ->subject('Nouveau message de contact')
            ->html($emailBody);

        // Envoyer l'email
        $mailer->send($email);

        // Ajouter un message flash et rediriger vers la page de contact
        $this->addFlash('success', 'Votre message a bien été envoyé.');

        return $this->redirectToRoute('app_user_contact'); // Redirection vers la route de contact
    }
}
