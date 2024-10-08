<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    private $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('google_maps_api_key', [$this, 'getGoogleMapsApiKey']),
        ];
    }

    public function getGoogleMapsApiKey()
    {
        return $this->params->get('google_maps_api_key');
    }
}