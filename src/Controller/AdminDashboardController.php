<?php

namespace App\Controller;

use App\Repository\DeckRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminDashboardController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository $deckRepository,
    ) {}

    #[Route('/admin/dashboard', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        if (!$request->getSession()->has('admin_user_id')) {
            return $this->redirectToRoute('admin_login');
        }

        $rows  = $this->deckRepository->findRecentAnonymized(30);
        $decks = array_map(function (array $row): array {
            $stats = $row['stats'] ? json_decode((string) $row['stats'], true) : null;
            return [
                'name'       => $row['name'],
                'format'     => $row['format'],
                'isPublic'   => (bool) $row['is_public'],
                'isDraft'    => (bool) $row['is_draft'],
                'createdAt'  => new \DateTimeImmutable($row['created_at']),
                'totalCards' => $stats['totalCards'] ?? null,
            ];
        }, $rows);

        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                'today'    => $this->deckRepository->countCreatedToday(),
                'thisWeek' => $this->deckRepository->countCreatedSince(new \DateTimeImmutable('-7 days')),
                'total'    => $this->deckRepository->countTotal(),
            ],
            'decks' => $decks,
        ]);
    }
}
