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

        $locale = $this->requestStack->getCurrentRequest()?->query->get('locale', 'fr') ?? 'fr';

        if (isset($data['stats']['hero'])) {
            $hero = &$data['stats']['hero'];
            if (is_array($hero['name'] ?? null)) {
                $hero['name'] = $hero['name'][$locale] ?? $hero['name']['fr'] ?? null;
            }
            if (is_array($hero['imagePath'] ?? null)) {
                $hero['imagePath'] = $hero['imagePath'][$locale] ?? $hero['imagePath']['fr'] ?? null;
            }
        }

        // Only enrich on detail view (deckCards present)
        if (empty($data['deckCards'])) {
            return $data;
        }

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

            foreach (['effect1', 'effect2', 'effect3'] as $effectKey) {
                if (!array_key_exists($effectKey, $card) || $card[$effectKey] === null) {
                    continue;
                }
                $effect = $card[$effectKey];
                $tmp['effects'][] = [
                    'text'             => is_array($effect['text']) ? ($effect['text'][$locale] ?? $effect['text']['fr'] ?? null) : $effect['text'],
                    'abilityTrigger'   => [
                        'alteredId' => $effect['abilityTrigger']['alteredId'] ?? null,
                        'text'      => is_array($effect['abilityTrigger']['text'] ?? null) ? ($effect['abilityTrigger']['text'][$locale] ?? $effect['abilityTrigger']['text']['fr'] ?? null) : ($effect['abilityTrigger']['text'] ?? null),
                    ],
                    'abilityCondition' => [
                        'alteredId' => $effect['abilityCondition']['alteredId'] ?? null,
                        'text'      => is_array($effect['abilityCondition']['text'] ?? null) ? ($effect['abilityCondition']['text'][$locale] ?? $effect['abilityCondition']['text']['fr'] ?? null) : ($effect['abilityCondition']['text'] ?? null),
                    ],
                    'abilityEffect'    => [
                        'alteredId' => $effect['abilityEffect']['alteredId'] ?? null,
                        'text'      => is_array($effect['abilityEffect']['text'] ?? null) ? ($effect['abilityEffect']['text'][$locale] ?? $effect['abilityEffect']['text']['fr'] ?? null) : ($effect['abilityEffect']['text'] ?? null),
                    ],
                ];
            }

            $cards[] = $tmp;
        }

        unset($data['deckCards']);
        $data['cards'] = $cards;

        return $data;
    }
}
