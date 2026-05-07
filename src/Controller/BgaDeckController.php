<?php

namespace App\Controller;

use App\Entity\Deck;
use App\Entity\User;
use App\Repository\DeckRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

class BgaDeckController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository      $deckRepository,
        private readonly SerializerInterface $serializer,
        private readonly Security            $security,
    ) {}

    #[Route('/api/bga/decks', name: 'api_bga_decks_collection', methods: ['GET'])]
    public function collection(Request $request): JsonResponse
    {
        $page        = max(1, (int) $request->query->get('page', 1));
        $name        = (string) $request->query->get('name', '');
        $hero        = (string) $request->query->get('hero', '');
        $factions    = $request->query->all('factions') ?: ['AX', 'BR', 'MU', 'LY', 'OR', 'YZ'];
        $eventFormat = strtoupper((string) $request->query->get('eventFormat', ''));

        $format = match ($eventFormat) {
            'STANDARD'  => 'standard',
            'NO_UNIQUE' => 'nuc',
            'SINGLETON' => 'singleton',
            default     => '',
        };

        $itemsPerPage = 20;
        $user         = $this->security->getUser();
        $user         = $user instanceof User ? $user : null;

        $decks    = $this->deckRepository->findBgaDecks($user, $page, $itemsPerPage, $name, $factions, $hero, $format);
        $total    = $this->deckRepository->countBgaDecks($user, $name, $factions, $hero, $format);
        $lastPage = max(1, (int) ceil($total / $itemsPerPage));

        $deckData = array_map(function (Deck $deck) {
            $heroRef = $deck->getStats()['hero']['reference'] ?? null;
            $faction = $heroRef ? (explode('_', $heroRef)[3] ?? null) : null;

            return [
                'hero'      => $heroRef,
                'faction'   => $faction,
                'apiId'     => (string) $deck->getId(),
                'deckName'  => $deck->getName(),
                'cardCount' => $deck->getStats()['totalCards'] ?? 0,
            ];
        }, $decks);

        return $this->json([
            'success' => 1,
            'content' => [
                'decks'      => $deckData,
                'pagination' => [
                    'current'  => (string) $page,
                    'last'     => (string) $lastPage,
                    'previous' => $page > 1 ? (string) ($page - 1) : '',
                    'next'     => $page < $lastPage ? (string) ($page + 1) : '',
                ],
            ],
        ]);
    }

    #[Route(
        '/api/bga/decks/{id}',
        name: 'api_bga_decks_item',
        requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
        methods: ['GET'],
    )]
    public function item(string $id): JsonResponse
    {
        $deck = $this->deckRepository->find($id);

        if (!$deck) {
            throw new NotFoundHttpException();
        }

        $data = $this->serializer->normalize($deck, 'json', [
            'groups' => ['deck:read', 'deck:read:detail'],
            'view'   => 'bga',
        ]);

        return $this->json($data);
    }
}
