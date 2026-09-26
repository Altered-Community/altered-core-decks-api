<?php

namespace App\Client;

use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AlteredCoreClient implements CardDataProviderInterface
{
    private const string CARD_CACHE_TAG = 'altered_core_card';
    private const int CARD_GROUP_PAGE_SIZE = 1000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TagAwareCacheInterface $cache,
        private readonly string $alteredCoreUrl,
    ) {
    }

    public function getName(): string
    {
        return 'altered_core';
    }

    public function getBaseUrl(): string
    {
        return $this->alteredCoreUrl;
    }

    /**
     * Fetch card data for a list of references from altered-core.
     * Results are cached per reference for 1 hour.
     *
     * @param string[] $references
     *
     * @return array<string, array> reference => card data
     */
    public function getCardsByReferences(array $references, string $locale = 'fr'): array
    {
        if (empty($references)) {
            return [];
        }

        $missing = [];
        $result = [];

        // Check cache per reference
        foreach ($references as $ref) {
            $cacheKey = 'card_'.md5($ref.'_'.$locale);
            $cached = $this->cache->get($cacheKey, function (ItemInterface $item) {
                $item->expiresAfter(3600);

                return null; // sentinel: missing from cache, will be batch-fetched
            });

            if (null !== $cached) { // @phpstan-ignore notIdentical.alwaysFalse
                $result[$ref] = $cached;
            } else {
                $missing[] = $ref;
            }
        }

        if (empty($missing)) { // @phpstan-ignore empty.variable
            return $result;
        }

        // Batch fetch missing references
        $response = $this->httpClient->request('POST', $this->alteredCoreUrl.'/api/cards/batch', [
            'json' => ['references' => $missing],
            'query' => ['locale' => $locale],
        ]);

        $cards = $response->toArray();

        // Index by reference and cache individually
        foreach ($cards as $card) {
            $ref = $card['reference'] ?? null;
            if (!$ref) {
                continue;
            }

            $result[$ref] = $card;

            $cacheKey = 'card_'.md5($ref.'_'.$locale);
            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, function (ItemInterface $item) use ($card) {
                $item->expiresAfter(3600);
                $item->tag(self::CARD_CACHE_TAG);

                return $card;
            });
        }

        return $result;
    }

    /**
     * Fetch card data for a list of references from altered-core.
     * Results are cached per reference for 1 hour.
     *
     * @return array<string, array> reference => card data
     */
    public function getCardByReferences(string $reference, string $locale = 'en'): array
    {
        $cacheKey = 'card_'.md5($reference.'_'.$locale);
        $cached = $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(3600);

            return null; // sentinel: missing from cache, will be fetched
        });

        if (null !== $cached) { // @phpstan-ignore notIdentical.alwaysFalse
            return $cached;
        }

        // Batch fetch missing references
        $response = $this->httpClient->request('GET', $this->alteredCoreUrl.'/api/cards/reference/'.$reference.'?locale='.$locale);

        $card = $response->toArray();

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($card) {
            $item->expiresAfter(3600);
            $item->tag(self::CARD_CACHE_TAG);

            return $card;
        });

        return $card;
    }

    /**
     * Drops every cached card payload, so the next reads see fresh data (e.g. new
     * gameplayFormat tags after a Frontier pool change).
     */
    public function invalidateCardCache(): void
    {
        $this->cache->invalidateTags([self::CARD_CACHE_TAG]);
    }

    /**
     * Slugs of every CardGroup tagged with $gameplayFormat on altered-core, read from
     * PostgreSQL (CardGroup collection, not the Meilisearch-backed card search), walking the
     * collection with the afterId keyset cursor. Not cached: callers want the live allowlist.
     *
     * @return string[]
     */
    public function getCardGroupSlugsByGameplayFormat(string $gameplayFormat): array
    {
        $slugs = [];
        $afterId = 0;

        do {
            $query = ['gameplayFormat' => $gameplayFormat, 'itemsPerPage' => self::CARD_GROUP_PAGE_SIZE, 'page' => 1];
            if ($afterId > 0) {
                $query['afterId'] = $afterId;
            }

            $members = $this->httpClient->request('GET', $this->alteredCoreUrl.'/api/card_groups', ['query' => $query])
                ->toArray()['member'] ?? [];

            $previousAfterId = $afterId;
            foreach ($members as $cardGroup) {
                $slugs[] = (string) $cardGroup['slug'];
                $afterId = max($afterId, (int) $cardGroup['id']);
            }
        } while (self::CARD_GROUP_PAGE_SIZE === count($members) && $afterId > $previousAfterId);

        return $slugs;
    }
}
