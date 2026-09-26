<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Card data and altered-core mock responses for Frontier pool tests.
 */
final class FrontierFixtures
{
    public const string UNIQUE_REF = 'ALT_CORE_B_AX_20_U_101';

    /**
     * A Standard-legal deck (1 hero + 13 commons × 3) plus one Unique.
     *
     * @return list<array{cardReference: string, quantity: int}>
     */
    public static function deckCards(): array
    {
        $deckCards = [['cardReference' => 'ALT_CORE_B_AX_1_C', 'quantity' => 1]];
        for ($i = 2; $i <= 14; ++$i) {
            $deckCards[] = ['cardReference' => sprintf('ALT_CORE_B_AX_%d_C', $i), 'quantity' => 3];
        }
        $deckCards[] = ['cardReference' => self::UNIQUE_REF, 'quantity' => 1];

        return $deckCards;
    }

    /**
     * altered-core card payloads for deckCards(), as /api/cards/batch returns them.
     *
     * @return list<array<string, mixed>>
     */
    public static function cards(bool $uniqueInFrontier): array
    {
        $cards = [self::card('ALT_CORE_B_AX_1_C', 'HERO_MAIN', 'COMMON', [])];
        for ($i = 2; $i <= 14; ++$i) {
            $cards[] = self::card(sprintf('ALT_CORE_B_AX_%d_C', $i), 'PERMANENT', 'COMMON', []);
        }
        $cards[] = self::card(self::UNIQUE_REF, 'PERMANENT', 'UNIQUE', $uniqueInFrontier ? ['STANDARD', 'FRONTIER'] : ['STANDARD']);

        return $cards;
    }

    /**
     * Response factory for the altered-core MockHttpClient: serves the Frontier allowlist on
     * /api/card_groups (as CardGroup slugs) and the requested cards on /api/cards/batch.
     *
     * @param list<array<string, mixed>> $cards
     * @param list<string>               $frontierSlugs
     */
    public static function alteredCore(array $cards, array $frontierSlugs): \Closure
    {
        return static function (string $method, string $url, array $options) use ($cards, $frontierSlugs): MockResponse {
            if (str_contains($url, '/api/card_groups')) {
                $members = [];
                foreach ($frontierSlugs as $i => $slug) {
                    $members[] = ['id' => $i + 1, 'slug' => $slug, 'gameplayFormat' => ['FRONTIER']];
                }

                return self::json(['member' => $members, 'totalItems' => count($members)]);
            }

            $requested = json_decode($options['body'] ?? '{}', true)['references'] ?? [];

            return self::json(array_values(array_filter(
                $cards,
                static fn (array $card): bool => in_array($card['reference'], $requested, true),
            )));
        };
    }

    public static function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data), ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']]);
    }

    private static function card(string $ref, string $type, string $rarity, array $gameplayFormat): array
    {
        return [
            'reference' => $ref,
            'name' => $ref,
            'cardType' => ['reference' => $type],
            'faction' => ['code' => 'AX'],
            'rarity' => ['reference' => $rarity],
            'gameplayFormat' => $gameplayFormat,
        ];
    }
}
