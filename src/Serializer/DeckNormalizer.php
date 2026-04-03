<?php

namespace App\Serializer;

use App\Client\AlteredCoreClient;
use App\Entity\Deck;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class DeckNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'DECK_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        private readonly AlteredCoreClient $alteredCoreClient,
        private readonly RequestStack      $requestStack,
    ) {}

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Deck
            && !($context[self::ALREADY_CALLED] ?? false);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Deck::class => false];
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $context[self::ALREADY_CALLED] = true;

        /** @var Deck $object */
        $data = $this->normalizer->normalize($object, $format, $context);

        // Only enrich on detail view (deckCards present)
        if (empty($data['deckCards'])) {
            return $data;
        }

        $locale     = $this->requestStack->getCurrentRequest()?->query->get('locale', 'fr') ?? 'fr';
        $references = array_column($data['deckCards'], 'cardReference');
        $cardsData  = $this->alteredCoreClient->getCardsByReferences($references, $locale);

        $cards = [];
        $tmp = [];
        foreach ($data['deckCards'] as $deckCard) {
            $ref      = $deckCard['cardReference'] ?? null;
            $card     = $cardsData[$ref] ?? [];
            $nameMap  = $card['name'] ?? null;
            $imageMap = $card['imagePath'] ?? null;

            $tmp = [
                'cardReference'     => $ref,
                'quantity'          => $deckCard['quantity'],
                'name'              => is_array($nameMap) ? ($nameMap[$locale] ?? $nameMap['fr'] ?? null) : $nameMap,
                'factionCode'       => $card['faction']['code'] ?? null,
                'cardTypeReference' => $card['cardType']['reference'] ?? null,
                'mainCost'          => $card['mainCost'] ?? null,
                'recallCost'        => $card['recallCost'] ?? null,
                'oceanPower'        => $card['oceanPower'] ?? null,
                'mountainPower'     => $card['mountainPower'] ?? null,
                'forestPower'       => $card['forestPower'] ?? null,
                'imagePath'         => is_array($imageMap) ? ($imageMap[$locale] ?? $imageMap['fr'] ?? null) : $imageMap,
                'effects'           => []
            ];

            if(array_key_exists('effect1', $card)) {
                $tmp['effects'][] = [
                    'text' => is_array($card['effect1']['text']) ? ($card['effect1']['text'][$locale] ?? $card['effect1']['text']['fr'] ?? null) : $card['effect1']['text'],
                    'abilityTrigger' => [
                        'alteredId' => $card['effect1']['abilityTrigger']['alteredId'] ?? null,
                        'text' => $card['effect1']['abilityTrigger']['text']['en'] ?? null,
                    ],
                    'abilityCondition' => [
                        'alteredId' => $card['effect1']['abilityCondition']['alteredId'] ?? null,
                        'text' => $card['effect1']['abilityCondition']['text']['en'] ?? null,
                    ],
                    'abilityEffect' => [
                        'alteredId' => $card['effect1']['abilityEffect']['alteredId'] ?? null,
                        'text' => $card['effect1']['abilityEffect']['text']['en'] ?? null,
                    ]
                ];
            }

            if(array_key_exists('effect2', $card)) {
                $tmp['effects'][] = [
                    'text' => is_array($card['effect2']['text']) ? ($card['effect2']['text'][$locale] ?? $card['effect2']['text']['fr'] ?? null) : $card['effect2']['text'],
                    'abilityTrigger' => [
                        'alteredId' => $card['effect2']['abilityTrigger']['alteredId'] ?? null,
                        'text' => $card['effect2']['abilityTrigger']['text']['en'] ?? null,
                    ],
                    'abilityCondition' => [
                        'alteredId' => $card['effect2']['abilityCondition']['alteredId'] ?? null,
                        'text' => $card['effect2']['abilityCondition']['text']['en'] ?? null,
                    ],
                    'abilityEffect' => [
                        'alteredId' => $card['effect2']['abilityEffect']['alteredId'] ?? null,
                        'text' => $card['effect2']['abilityEffect']['text']['en'] ?? null,
                    ]
                ];
            }

            if(array_key_exists('effect3', $card)) {
                $tmp['effects'][] = [
                    'text' => is_array($card['effect3']['text']) ? ($card['effect3']['text'][$locale] ?? $card['effect3']['text']['fr'] ?? null) : $card['effect3']['text'],
                    'abilityTrigger' => [
                        'alteredId' => $card['effect3']['abilityTrigger']['alteredId'] ?? null,
                        'text' => $card['effect3']['abilityTrigger']['text']['en'] ?? null,
                    ],
                    'abilityCondition' => [
                        'alteredId' => $card['effect3']['abilityCondition']['alteredId'] ?? null,
                        'text' => $card['effect3']['abilityCondition']['text']['en'] ?? null,
                    ],
                    'abilityEffect' => [
                        'alteredId' => $card['effect3']['abilityEffect']['alteredId'] ?? null,
                        'text' => $card['effect3']['abilityEffect']['text']['en'] ?? null,
                    ]
                ];
            }

            $cards[] = $tmp;
        }

        unset($data['deckCards']);
        $data['cards'] = $cards;

        return $data;
    }
}
