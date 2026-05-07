<?php

namespace App\Controller;

use App\Repository\DeckRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

class PublicDeckController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository     $deckRepository,
        private readonly SerializerInterface $serializer,
    ) {}

    #[Route('/api/decks/public', name: 'api_decks_public', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $page         = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(1000, max(1, (int) $request->query->get('itemsPerPage', 30)));
        $hero         = $request->query->get('hero') ?: null;

        $decks = $this->deckRepository->findPublic($page, $itemsPerPage, $hero);
        $total = $this->deckRepository->countPublic($hero);

        $data = $this->serializer->normalize($decks, 'json', ['groups' => ['deck:read']]);

        return $this->json([
            'member'     => $data,
            'totalItems' => $total,
        ]);
    }
}
