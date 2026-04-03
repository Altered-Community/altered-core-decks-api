<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class FormatController extends AbstractController
{
    #[Route('/api/formats', name: 'api_formats', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json([
            [
                'code'     => 'nuc',
                'label'    => 'NUC',
                'minCards' => 39,
                'maxCards' => 59,
                'limits'   => [
                    'unique'            => 0,
                    'rare'              => 15,
                    'exalted'           => 3,
                    'maxCopiesPerName'  => 3,
                    'maxCopiesPerRarity'=> null,
                ],
            ],
            [
                'code'     => 'standard',
                'label'    => 'Standard',
                'minCards' => 39,
                'maxCards' => 59,
                'limits'   => [
                    'unique'            => 3,
                    'rare'              => 15,
                    'exalted'           => 3,
                    'maxCopiesPerName'  => 3,
                    'maxCopiesPerRarity'=> null,
                ],
            ],
            [
                'code'     => 'singleton',
                'label'    => 'Singleton',
                'minCards' => 59,
                'maxCards' => 79,
                'limits'   => [
                    'unique'            => null,
                    'rare'              => null,
                    'exalted'           => null,
                    'maxCopiesPerName'  => 3,
                    'maxCopiesPerRarity'=> 1,
                ],
                'uniqueLimitsByHero' => [
                    3 => ['teija', 'kojo', 'basira', 'sigismar', 'nevenka', 'fen', 'treyst'],
                    4 => ['subhash', 'isaree', 'atsadi', 'arjun', 'kauri', 'rin', 'zhen', 'akesha'],
                    5 => ['sierra', 'sol', 'auraq', 'nadir', 'waru', 'gulrang', 'lindiwe', 'afanas', 'moyo'],
                ],
            ],
        ]);
    }
}
