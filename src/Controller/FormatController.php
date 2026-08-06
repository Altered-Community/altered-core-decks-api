<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class FormatController extends AbstractController
{
    #[Route('/api/formats', name: 'api_formats', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $includeHidden = $request->query->getBoolean('hiddenFormats');

        $formats = [
            [
                'code' => 'sandbox',
                'label' => 'Sandbox',
                'minCards' => 4,
                'maxCards' => 100,
                'allowBanned' => true,
                'allowSuspended' => true,
                'limits' => [
                    'unique' => null,
                    'rare' => null,
                    'exalted' => null,
                    'maxCopiesPerName' => null,
                    'maxCopiesPerRarity' => null,
                ],
            ],
            [
                'code' => 'test',
                'label' => 'Test',
                'hidden' => true,
                'minCards' => 4,
                'maxCards' => 100,
                'allowBanned' => true,
                'allowSuspended' => true,
                'limits' => [
                    'unique' => null,
                    'rare' => null,
                    'exalted' => null,
                    'maxCopiesPerName' => null,
                    'maxCopiesPerRarity' => null,
                ],
            ],
            [
                'code' => 'nuc',
                'label' => 'NUC',
                'minCards' => 39,
                'maxCards' => 59,
                'allowBanned' => false,
                'allowSuspended' => false,
                'limits' => [
                    'unique' => 0,
                    'rare' => 15,
                    'exalted' => 3,
                    'maxCopiesPerName' => 3,
                    'maxCopiesPerRarity' => null,
                ],
            ],
            [
                'code' => 'standard',
                'label' => 'Standard',
                'minCards' => 39,
                'maxCards' => 59,
                'allowBanned' => false,
                'allowSuspended' => false,
                'limits' => [
                    'unique' => 3,
                    'rare' => 15,
                    'exalted' => 3,
                    'maxCopiesPerName' => 3,
                    'maxCopiesPerRarity' => null,
                ],
            ],
            [
                'code' => 'frontier',
                'label' => 'Frontier',
                'minCards' => 39,
                'maxCards' => 59,
                'allowBanned' => false,
                'allowSuspended' => false,
                'limits' => [
                    'unique' => 3,
                    'rare' => 15,
                    'exalted' => 3,
                    'maxCopiesPerName' => 3,
                    'maxCopiesPerRarity' => null,
                ],
            ],
            [
                'code' => 'singleton',
                'label' => 'Singleton',
                'minCards' => 59,
                'maxCards' => 79,
                'allowBanned' => false,
                'allowSuspended' => false,
                'limits' => [
                    'unique' => null,
                    'rare' => null,
                    'exalted' => null,
                    'maxCopiesPerName' => 3,
                    'maxCopiesPerRarity' => 1,
                ],
                'uniqueLimitsByHero' => [
                    3 => ['teija', 'kojo', 'basira', 'sigismar', 'nevenka', 'fen', 'treyst'],
                    4 => ['subhash', 'isaree', 'atsadi', 'arjun', 'kauri', 'rin', 'zhen', 'akesha'],
                    5 => ['sierra', 'sol', 'auraq', 'nadir', 'waru', 'gulrang', 'lindiwe', 'afanas', 'moyo'],
                ],
            ],
            [
                'code' => 'singleton_nuc',
                'label' => 'Singleton NUC',
                'minCards' => 59,
                'maxCards' => 79,
                'allowBanned' => false,
                'allowSuspended' => false,
                'limits' => [
                    'unique' => 0,
                    'rare' => null,
                    'exalted' => null,
                    'maxCopiesPerName' => 3,
                    'maxCopiesPerRarity' => 1,
                ],
            ],
            [
                'code' => 'sealed',
                'label' => 'Sealed',
                'minCards' => 29,
                'maxCards' => null,
                'allowBanned' => true,
                'allowSuspended' => true,
                'limits' => [
                    'unique' => null,
                    'rare' => null,
                    'exalted' => null,
                    'maxCopiesPerName' => null,
                    'maxCopiesPerRarity' => null,
                ],
            ],
        ];

        if (!$includeHidden) {
            $formats = array_values(array_filter($formats, fn (array $format) => empty($format['hidden'])));
        }

        return $this->json($formats);
    }
}
