<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\DeckRepository;
use App\Repository\DeckUpvoteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class PublicDeckController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository $deckRepository,
        private readonly DeckUpvoteRepository $deckUpvoteRepository,
        private readonly NormalizerInterface $serializer,
    ) {
    }

    #[Route('/api/decks/public/heroes', name: 'api_decks_public_heroes', methods: ['GET'])]
    public function listHeroes(Request $request): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');

        return $this->json($this->deckRepository->findPublicHeroes($locale));
    }

    #[Route('/api/decks/public', name: 'api_decks_public', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(1000, max(1, (int) $request->query->get('itemsPerPage', 30)));
        $hero = $request->query->get('hero') ?: null;
        $cardName = $request->query->get('cardName') ?: null;

        $orderBy = match ($request->query->get('sortBy', 'recent')) {
            'upvotes' => 'upvote_count',
            'views' => 'view_count',
            default => 'created_at',
        };

        $decks = $this->deckRepository->findPublic($page, $itemsPerPage, $hero, $cardName, $orderBy);
        $total = $this->deckRepository->countPublic($hero, $cardName);

        /** @var array<int, array<string, mixed>> $data */
        $data = $this->serializer->normalize($decks, 'json', ['groups' => ['deck:read']]) ?? [];

        $upvotedSet = [];
        $user = $this->getUser();
        if ($user instanceof User && !empty($decks)) {
            $upvotedIds = $this->deckUpvoteRepository->findUpvotedDeckIdsByUser($decks, $user);
            $upvotedSet = array_flip($upvotedIds);
        }

        foreach ($data as $i => $deck) {
            $data[$i]['hasUpvoted'] = isset($upvotedSet[$deck['id']]);
        }

        $lastPage = max(1, (int) ceil($total / $itemsPerPage));

        return $this->json([
            'member' => $data,
            'totalItems' => $total,
            'currentPage' => $page,
            'lastPage' => $lastPage,
            'nextPage' => $page < $lastPage ? $page + 1 : null,
            'previousPage' => $page > 1 ? $page - 1 : null,
        ]);
    }
}
