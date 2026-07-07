<?php

namespace App\Client;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Card data provider backed by taum/rust-cards-api (https://github.com/taum/rust-cards-api).
 *
 * Scaffolding only: this API's `GET /api/v2/card/{reference}` response only overlaps
 * partially with altered-core's shape (see AlteredCoreClient). Fields consumed elsewhere
 * in the app but not present here — imagePath, isBanned, isSuspended, cardType, rarity,
 * effect1/effect2/effect3, echoEffect1, artists[] — are left as TODO below rather than
 * guessed at, since getting them wrong silently breaks format validation and hero
 * detection. There is also no batch-by-references endpoint, so getCardsByReferences()
 * issues one request per reference.
 */
class RustCardsApiProvider implements CardDataProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $rustCardsApiUrl,
    ) {
    }

    public function getName(): string
    {
        return 'rust_cards_api';
    }

    /**
     * @param string[] $references
     *
     * @return array<string, array>
     */
    public function getCardsByReferences(array $references, string $locale = 'fr'): array
    {
        $result = [];

        foreach ($references as $reference) {
            $card = $this->getCardByReferences($reference, $locale);
            if (!empty($card)) {
                $result[$reference] = $card;
            }
        }

        return $result;
    }

    public function getCardByReferences(string $reference, string $locale = 'en'): array
    {
        $cacheKey = 'rust_card_'.md5($reference.'_'.$locale);
        $cached = $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(3600);

            return null; // sentinel: missing from cache, will be fetched
        });

        if (null !== $cached) { // @phpstan-ignore notIdentical.alwaysFalse
            return $cached;
        }

        $response = $this->httpClient->request('GET', $this->rustCardsApiUrl.'/api/v2/card/'.$reference);
        $card = $this->normalize($response->toArray());

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($card) {
            $item->expiresAfter(3600);

            return $card;
        });

        return $card;
    }

    /**
     * Maps the raw rust-cards-api response onto the field names consumers already
     * expect from AlteredCoreClient. Only fields directly present in the source
     * response are mapped — nothing here is inferred or guessed.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        return [
            'reference' => $raw['reference'] ?? null,
            'name' => $raw['name'] ?? null,
            'faction' => $raw['faction'] ?? null,
            'mainCost' => $raw['mainCost'] ?? null,
            'recallCost' => $raw['recallCost'] ?? null,
            'forestPower' => $raw['forestPower'] ?? null,
            'mountainPower' => $raw['mountainPower'] ?? null,
            'oceanPower' => $raw['oceanPower'] ?? null,
            'cardSubTypes' => $raw['cardSubTypes'] ?? null,
            'set' => $raw['set'] ?? null,

            // TODO: not provided by rust-cards-api — needs a decision before this
            // provider can be used for format validation, hero detection or display.
            'imagePath' => null,
            'isBanned' => null,
            'isSuspended' => null,
            'cardType' => null,
            'rarity' => null,
            'artists' => null,
            'effect1' => null,
            'effect2' => null,
            'effect3' => null,
            'echoEffect1' => null,
        ];
    }
}
