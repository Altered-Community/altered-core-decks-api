<?php

namespace App\Controller;

use App\Client\AlteredCoreClient;
use App\Entity\User;
use App\Repository\DeckRepository;
use App\Serializer\BgaDeckSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use App\Entity\Deck;

class BgaDeckController extends AbstractController
{
    private const BGA_VALID_FORMATS = ['standard', 'nuc', 'sandbox'];

    public function __construct(
        private readonly DeckRepository   $deckRepository,
        private readonly Security         $security,
        private readonly SerializerInterface $serializer,
        private readonly AlteredCoreClient $alteredCoreClient
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
            'SANDBOX'   => 'sandbox',
            default     => '',
        };

        $itemsPerPage = 20;
        $user         = $this->security->getUser();
        $user         = $user instanceof User ? $user : null;

        /*$decks    = $this->deckRepository->findBgaDecks($user, $page, $itemsPerPage, $name, $factions, $hero, $format, self::BGA_VALID_FORMATS);
        $total    = $this->deckRepository->countBgaDecks($user, $name, $factions, $hero, $format, self::BGA_VALID_FORMATS);*/
        $allDecks = $this->deckRepository->findBy(['format' => 'STANDARD']);
        $allDecks = array_slice($allDecks, 0, 200);


        foreach ($allDecks as $key => $deck) {
            $check = $deck->getStats()['hero']['reference'] ?? null;
            if(!$check) {
                unset($allDecks[$key]);
            }
        }
        $total = count($allDecks);
        $lastPage = max(1, (int) ceil($total / $itemsPerPage));

        $decks = array_slice(
            $allDecks,
            ($page - 1) * $itemsPerPage,
            $itemsPerPage
        );

        $deckData = array_map(function (Deck $deck) {
            $heroRef = $deck->getStats()['hero']['reference'] ?? null;
            $faction = $heroRef ? (explode('_', $heroRef)[3] ?? null) : null;

            return [
                'alterator' => ['reference' => $heroRef],
                'faction'   => ['reference' => $faction],
                'id'        => (string) $deck->getId(),
                'name'      => $deck->getName(),
                'cardCount' => $deck->getStats()['totalCards'] ?? 0,
                'format'    => $deck->getFormat(),
            ];
        }, $decks);

        $lastPage = max(1, (int) ceil($total / $itemsPerPage));

        $hydraView = [
            '@id' => sprintf(
                '/api/bga/decks?itemsPerPage=%d&page=%d',
                $itemsPerPage,
                $page
            ),
            '@type' => 'hydra:PartialCollectionView',

            'hydra:first' => sprintf(
                '/api/bga/decks?itemsPerPage=%d&page=1',
                $itemsPerPage,
            ),

            'hydra:last' => sprintf(
                '/api/bga/decks?itemsPerPage=%d&page=%d',
                $itemsPerPage,
                $lastPage
            ),
        ];

        if ($page < $lastPage) {
            $hydraView['hydra:next'] = sprintf(
                '/api/bga/decks?itemsPerPage=%d&page=%d',
                $itemsPerPage,
                $page + 1
            );
        }

        if ($page > 1) {
            $hydraView['hydra:previous'] = sprintf(
                '/api/bga/decks?itemsPerPage=%d&page=%d',
                $itemsPerPage,
                $page - 1
            );
        }

        return $this->json([
            'hydra:member' => $deckData,
            'hydra:view'   => $hydraView,
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

    #[Route(
        '/api/bga/cards/{reference}',
        name: 'api_bga_cards_item',
        methods: ['GET'],
    )]
    public function card(string $reference): JsonResponse
    {
        $card = $this->alteredCoreClient->getCardByReferences($reference);

        $cardElements[] = $this->generateMainEffect($card);

        $cardElements = array_filter($cardElements);

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
            'cardElements' => $cardElements,
        ]);
    }

    private function generateMainEffect(array $card): array
    {
        $cardEffectDisplays = [];
        if(array_key_exists('effect1', $card)) {
            $cardEffectDisplays[] = $this->generateEffectDisplay($card['effect1'], 1);
        }
        if(array_key_exists('effect2', $card)) {
            $cardEffectDisplays[] = $this->generateEffectDisplay($card['effect2'], 2);
        }
        if(array_key_exists('effect3', $card)) {
            $cardEffectDisplays[] = $this->generateEffectDisplay($card['effect3'], 3);
        }


        return [
            'cardElementType' => ['reference' => 'MAIN_EFFECT'],
            'cardEffectDisplays' => $cardEffectDisplays,
        ];
    }

    private function generateEffectDisplay(array $effect, int $sequence)
    {
        return [
            'cardEffect' => [
                'cardEffectElements' => [
                    [
                        'idGd' => $effect['abilityTrigger']['alteredId'],
                        'type' => 'TRIGGER',
                        'text' => $effect['abilityTrigger']['text']
                    ],
                    [
                        'idGd' => $effect['abilityCondition']['alteredId'],
                        'type' => 'OUTPUT',
                        'text' => $effect['abilityCondition']['text']
                    ],
                    [
                        'idGd' => $effect['abilityEffect']['alteredId'],
                        'type' => 'CONDITION',
                        'text' => $effect['abilityEffect']['text']
                    ],
                ],
                'reference' => $effect['abilityKey'],
                'sequence' => $sequence
            ]
        ];
    }
}
