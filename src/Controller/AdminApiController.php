<?php

namespace App\Controller;

use App\Repository\DeckRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminApiController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository $deckRepository,
    ) {}

    #[Route('/stats', name: 'admin_api_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        return $this->json([
            'decksToday'    => $this->deckRepository->countCreatedToday(),
            'decksThisWeek' => $this->deckRepository->countCreatedSince(new \DateTimeImmutable('-7 days')),
            'decksTotal'    => $this->deckRepository->countTotal(),
        ]);
    }

    #[Route('/decks', name: 'admin_api_decks', methods: ['GET'])]
    public function decks(): JsonResponse
    {
        $rows = $this->deckRepository->findRecentAnonymized(30);

        $decks = array_map(function (array $row): array {
            $stats = $row['stats'] ? json_decode((string) $row['stats'], true) : null;

            return [
                'id'         => $row['id'],
                'name'       => $row['name'],
                'format'     => $row['format'],
                'isPublic'   => (bool) $row['is_public'],
                'isDraft'    => (bool) $row['is_draft'],
                'createdAt'  => $row['created_at'],
                'totalCards' => $stats['totalCards'] ?? null,
            ];
        }, $rows);

        return $this->json($decks);
    }
}
