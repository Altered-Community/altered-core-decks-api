<?php

namespace App\Serializer;

use App\Entity\Deck;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class BgaDeckSerializer
{
    public function __construct(
        private NormalizerInterface $serializer,
    ) {}

    public function collectionEntry(Deck $deck): array
    {
        $heroRef = $deck->getStats()['hero']['reference'] ?? null;
        $faction = $heroRef ? (explode('_', $heroRef)[3] ?? null) : null;

        return [
            'hero'      => $heroRef,
            'faction'   => $faction,
            'apiId'     => (string) $deck->getId(),
            'deckName'  => $deck->getName(),
            'cardCount' => $deck->getStats()['totalCards'] ?? 0,
        ];
    }

    public function adminRow(Deck $deck): array
    {
        $heroRef = $deck->getStats()['hero']['reference'] ?? null;
        $parts   = $heroRef ? explode('_', $heroRef) : [];

        return [
            'id'         => (string) $deck->getId(),
            'name'       => $deck->getName(),
            'format'     => $deck->getFormat(),
            'heroRef'    => $heroRef,
            'faction'    => $parts[3] ?? null,
            'totalCards' => $deck->getStats()['totalCards'] ?? null,
        ];
    }

    public function normalizeItem(Deck $deck): array
    {
        return $this->serializer->normalize($deck, 'json', [
            'groups' => ['deck:read', 'deck:read:detail'],
            'view'   => 'bga',
        ]);
    }

    public function normalizeCollection(array $decks): array
    {
        return array_map(fn (Deck $deck) => $this->collectionEntry($deck), $decks);
    }
}
