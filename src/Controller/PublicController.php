<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/public')]
class PublicController extends AbstractController
{
    #[Route('/contact', name: 'app_contact')]
    public function contact(): Response
    {
        return $this->render('autres/contact.html.twig');
    }

    #[Route('/cookies', name: 'app_cookies')]
    public function cookies(): Response
    {
        return $this->render('autres/cookies.html.twig');
    }
}