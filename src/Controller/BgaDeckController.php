<?php

namespace App\Controller;

use App\Client\CardDataProviderFactory;
use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Entity\User;
use App\Enum\DeckFormat;
use App\Repository\DeckRepository;
use App\Serializer\BgaDeckSerializer;
use App\Validator\Format\DeckFormatValidatorFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

class BgaDeckController extends AbstractController
{
    // NOTE: 'sealed' is deliberately absent here — its BGA deck-LIST call never reaches
    // this controller at all (altered-bga-api answers it directly from altered-draft,
    // see SealedDecklistHandler in that repo). Only the deck-CONTENT call below
    // (item()) ever sees a sealed deck, forwarded normally like any other format.
    private const BGA_VALID_FORMATS = ['standard', 'nuc', 'singleton_nuc', 'sandbox', 'test', 'frontier'];

    public function __construct(
        private readonly DeckRepository $deckRepository,
        private readonly Security $security,
        private readonly BgaDeckSerializer $bgaDeckSerializer,
        private readonly CardDataProviderFactory $cardDataProviderFactory,
        private readonly DeckFormatValidatorFactory $validatorFactory,
    ) {
    }

    #[Route('/api/bga/decks', name: 'api_bga_decks_collection', methods: ['GET'])]
    public function collection(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(100, max(1, (int) $request->query->get('itemsPerPage', 20)));
        $name = (string) $request->query->get('name', '');
        $hero = (string) $request->query->get('hero', '');
        // BGA sends faction.reference[] — PHP replaces dots with underscores when parsing query strings
        $factions = $request->query->all('faction_reference') ?: ['AX', 'BR', 'MU', 'LY', 'OR', 'YZ'];
        $eventFormat = strtoupper((string) $request->query->get('eventFormat', ''));

        $format = match ($eventFormat) {
            'STANDARD' => 'standard',
            'NO_UNIQUE' => 'nuc',
            'SANDBOX' => 'sandbox',
            'SINGLETON' => 'singleton',
            'SINGLETON_NUC' => 'singleton_nuc',
            'TEST' => 'test',
            'FRONTIER' => 'frontier',
            default => '',
        };

        $user = $this->security->getUser();
        $user = $user instanceof User ? $user : null;

        $decks = $this->deckRepository->findBgaDecks($user, $page, $itemsPerPage, $name, $factions, $hero, $format, self::BGA_VALID_FORMATS);
        $total = $this->deckRepository->countBgaDecks($user, $name, $factions, $hero, $format, self::BGA_VALID_FORMATS);

        $lastPage = max(1, (int) ceil($total / $itemsPerPage));

        $deckData = array_map(static function (Deck $deck): array {
            $heroRef = $deck->getStats()['hero']['reference'] ?? null;
            $faction = $heroRef ? (explode('_', $heroRef)[3] ?? null) : null;

            return [
                'alterator' => ['reference' => $heroRef],
                'faction' => ['reference' => $faction],
                'id' => (string) $deck->getId(),
                'name' => $deck->getName(),
                'cardQuantity' => $deck->getStats()['totalCards'] ?? 0,
                'format' => $deck->getFormat()?->value,
            ];
        }, $decks);

        return $this->json([
            'hydra:member' => $deckData,
            'hydra:totalItems' => $total,
            'hydra:view' => $this->buildHydraView($request, $page, $lastPage),
        ]);
    }

    private function buildHydraView(Request $request, int $page, int $lastPage): array
    {
        $params = $request->query->all();
        $buildUrl = static fn (int $p): string => '/api/bga/decks?'.http_build_query(array_merge($params, ['page' => $p]));

        $view = [
            '@id' => $buildUrl($page),
            '@type' => 'hydra:PartialCollectionView',
            'hydra:first' => $buildUrl(1),
            'hydra:last' => $buildUrl($lastPage),
        ];

        if ($page < $lastPage) {
            $view['hydra:next'] = $buildUrl($page + 1);
        }
        if ($page > 1) {
            $view['hydra:previous'] = $buildUrl($page - 1);
        }

        return $view;
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

        // The actual enforcement moment for tournament sealed decks (see altered-draft's
        // ROADMAP.md "Set 6 preview"): BGA fetches deck CONTENT here right before using
        // it in a real game, so this is where an out-of-pool deck must be rejected
        // outright — not just flagged. `tournamentSeed` (if any) rides as a query param
        // on this same request, forwarded by altered-bga-api's DeckContentHandler exactly
        // like eventFormat/tableId; AlteredDraftSealedPoolClient reads it directly off
        // the current request rather than needing it threaded through here.
        if (DeckFormat::Sealed === $deck->getFormat()) {
            $references = array_map(
                fn (DeckCard $dc) => $dc->getCardReference(),
                $deck->getDeckCards()->toArray()
            );
            $cardsData = $this->cardDataProviderFactory->getProvider()->getCardsByReferences($references);
            $errors = $this->validatorFactory->getValidator('sealed')->validate($deck, $cardsData);
            if (!empty($errors)) {
                throw new UnprocessableEntityHttpException(implode(' ', $errors));
            }
        }

        return $this->json($this->bgaDeckSerializer->normalizeItem($deck));
    }

    #[Route(
        '/api/bga/cards/{reference}',
        name: 'api_bga_cards_item',
        requirements: ['reference' => '[A-Z0-9_]+'],
        methods: ['GET'],
    )]
    public function card(string $reference): JsonResponse
    {
        $card = $this->cardDataProviderFactory->getProvider()->getCardByReferences($reference);

        if (empty($card)) {
            throw new NotFoundHttpException();
        }

        return $this->json([
            'reference' => $card['reference'],
            'mainFaction' => ['reference' => $card['faction']['code']],
            'name' => $card['name'],
            'cardType' => ['reference' => $card['cardType']['reference']],
            'subTypes' => $card['cardSubTypes'],
            'illustrator' => ['nickName' => $card['artists'][0]['name']],
            'elements' => [
                'MAIN_COST' => $card['mainCost'],
                'RECALL_COST' => $card['recallCost'],
                'FOREST_POWER' => $card['forestPower'],
                'MOUNTAIN_POWER' => $card['mountainPower'],
                'OCEAN_POWER' => $card['oceanPower'],
            ],
            'cardElements' => $this->bgaDeckSerializer->buildCardElements($card),
        ]);
    }
}
