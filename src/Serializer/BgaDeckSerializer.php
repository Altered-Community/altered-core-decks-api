<?php

namespace App\Serializer;

use App\Entity\Deck;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class BgaDeckSerializer
{
    public function __construct(
        private NormalizerInterface $serializer,
    ) {
    }

    public function collectionEntry(Deck $deck): array
    {
        $heroRef = $deck->getStats()['hero']['reference'] ?? null;
        $faction = $heroRef ? (explode('_', $heroRef)[3] ?? null) : null;

        return [
            'hero' => $heroRef,
            'faction' => $faction,
            'apiId' => (string) $deck->getId(),
            'deckName' => $deck->getName(),
            'cardCount' => $deck->getStats()['totalCards'] ?? 0,
        ];
    }

    public function adminRow(Deck $deck): array
    {
        $heroRef = $deck->getStats()['hero']['reference'] ?? null;
        $parts = $heroRef ? explode('_', $heroRef) : [];

        return [
            'id' => (string) $deck->getId(),
            'name' => $deck->getName(),
            'format' => $deck->getFormat()?->value,
            'heroRef' => $heroRef,
            'faction' => $parts[3] ?? null,
            'totalCards' => $deck->getStats()['totalCards'] ?? null,
        ];
    }

    public function normalizeItem(Deck $deck): array
    {
        return $this->serializer->normalize($deck, 'json', [
            'groups' => ['deck:read', 'deck:read:detail'],
            'view' => 'bga',
        ]);
    }

    public function normalizeCollection(array $decks): array
    {
        return array_map(fn (Deck $deck) => $this->collectionEntry($deck), $decks);
    }

    public function buildCardElements(array $card): array
    {
        return array_values(array_filter([$this->generateMainEffect($card), $this->generateSupportEffect($card)]));
    }

    private function generateSupportEffect(array $card): array
    {
        if (!array_key_exists('echoEffect1', $card)) {
            return [];
        }

        $cardEffectDisplays[] = $this->generateEffectDisplay($card['echoEffect1'], 1);

        return [
            'cardElementType' => ['reference' => 'SUPPORT_EFFECT'],
            'cardEffectDisplays' => $cardEffectDisplays,
        ];
    }

    private function generateMainEffect(array $card): array
    {
        $cardEffectDisplays = [];
        foreach (['effect1', 'effect2', 'effect3'] as $i => $effectKey) {
            if (array_key_exists($effectKey, $card)) {
                $cardEffectDisplays[] = $this->generateEffectDisplay($card[$effectKey], $i + 1);
            }
        }

        return [
            'cardElementType' => ['reference' => 'MAIN_EFFECT'],
            'cardEffectDisplays' => $cardEffectDisplays,
        ];
    }

    private function generateEffectDisplay(array $effect, int $sequence): array
    {
        return [
            'cardEffect' => [
                'cardEffectElements' => [
                    [
                        'idGd' => $effect['abilityTrigger']['alteredId'],
                        'type' => 'TRIGGER',
                        'text' => $effect['abilityTrigger']['text'],
                    ],
                    [
                        'idGd' => $effect['abilityCondition']['alteredId'],
                        'type' => 'OUTPUT',
                        'text' => $effect['abilityCondition']['text'],
                    ],
                    [
                        'idGd' => $effect['abilityEffect']['alteredId'],
                        'type' => 'CONDITION',
                        'text' => $effect['abilityEffect']['text'],
                    ],
                ],
                'reference' => $effect['abilityKey'],
                'sequence' => $sequence,
            ],
        ];
    }
}
