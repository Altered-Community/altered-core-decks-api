<?php

namespace App\Controller;

use App\Entity\Deck;
use App\Enum\BgaEventFormat;
use App\Repository\DeckRepository;
use App\Serializer\BgaDeckSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminBgaController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository $deckRepository,
        private readonly BgaDeckSerializer $bgaSerializer,
    ) {
    }

    #[Route('/admin/bga', name: 'admin_bga_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$request->getSession()->has('admin_user_id')) {
            return $this->redirectToRoute('admin_login');
        }

        $name = (string) $request->query->get('name', '');
        $eventFormat = strtoupper((string) $request->query->get('eventFormat', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $items = 20;

        $factions = ['AX', 'BR', 'MU', 'LY', 'OR', 'YZ'];
        $formats = BgaEventFormat::tryFrom($eventFormat)?->toDeckFormats() ?? [];
        $decks = $this->deckRepository->findBgaDecks(null, $page, $items, $name, $factions, '', $formats);
        $total = $this->deckRepository->countBgaDecks(null, $name, $factions, '', $formats);
        $lastPage = max(1, (int) ceil($total / $items));

        $rows = array_map(fn (Deck $deck) => $this->bgaSerializer->adminRow($deck), $decks);

        return $this->render('admin/bga/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'filters' => compact('name', 'eventFormat'),
            'eventFormats' => array_map(static fn (BgaEventFormat $f) => $f->value, BgaEventFormat::cases()),
        ]);
    }

    #[Route('/admin/bga/{id}', name: 'admin_bga_show', methods: ['GET'],
        requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function show(string $id, Request $request): Response
    {
        if (!$request->getSession()->has('admin_user_id')) {
            return $this->redirectToRoute('admin_login');
        }

        $deck = $this->deckRepository->find($id);
        if (!$deck) {
            throw $this->createNotFoundException();
        }

        $collectionEntry = $this->bgaSerializer->collectionEntry($deck);
        $faction = $collectionEntry['faction'];

        try {
            $itemData = $this->bgaSerializer->normalizeItem($deck);
            $itemJson = json_encode($itemData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $itemData = null;
            $itemJson = 'Erreur normalisation : '.$e->getMessage();
        }

        return $this->render('admin/bga/deck.html.twig', [
            'deck' => $deck,
            'faction' => $faction,
            'collectionJson' => json_encode(
                ['success' => 1, 'content' => ['decks' => [$collectionEntry]]],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ),
            'itemJson' => $itemJson,
            'hasErrors' => !empty($deck->getFormatErrors()),
            'formatErrors' => $deck->getFormatErrors() ?? [],
        ]);
    }
}
