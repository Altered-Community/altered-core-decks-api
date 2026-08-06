<?php

namespace App\Client;

use App\Entity\Deck;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a player's sealed pool from altered-draft, for SealedFormatValidator's
 * pool-membership check — keyed by decks-api's own deck id rather than a
 * `tournamentSeed`: `GET /api/tournament-pool-by-deck?deckId=...`. altered-draft links
 * a pool to its deck (`sealed_pools.deck_id`) essentially immediately as its frontend
 * syncs the deck, well before any real validation happens (a BGA game load, or a
 * non-draft save) — and binding a `tournamentSeed` to a tournament pool in the first
 * place already happens independently of decks-api (altered-bga-api calls altered-draft
 * directly on the BGA deck-LIST call). So decks-api never needs to see or forward
 * `tournamentSeed` at all: asking "the pool for MY deck" works uniformly for every call
 * site — the BGA deck-content call, a normal deck save, or a third-party deckbuilder
 * editing the deck through decks-api's own generic endpoint.
 *
 * Resetting a normal (casual) pool deletes its linked deck on altered-draft's side, so a
 * deck-id-to-pool link is either permanent (tournament pools never reset) or dies with
 * its deck (normal pools) — never silently stale. That makes it safe to cache every
 * response uniformly, keyed by (Keycloak sub, deck id): no deck id is ever reused, so
 * nothing needs invalidating early. Capped at CACHE_TTL rather than cached forever, so
 * memory doesn't accumulate one entry per sealed deck ever validated for no benefit.
 *
 * Forwards the caller's own bearer token to altered-draft, which verifies it against
 * the SAME Keycloak realm (`auth.altered.re/realms/players`) this app authenticates
 * against — so a caller can only ever fetch their OWN pool, same trust model as every
 * other endpoint here.
 */
readonly class AlteredDraftSealedPoolClient
{
    private const CACHE_TTL = 3600; // 1 hour — see class docblock.
    private const ERROR_TTL = 30;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private Security $security,
        private RequestStack $requestStack,
        private string $alteredDraftUrl,
    ) {
    }

    /**
     * Returns reference => available quantity for the given deck's sealed pool, or
     * null if there's no authenticated user, no linked pool, or the call to
     * altered-draft fails.
     *
     * @return array<string, int>|null
     */
    public function getPoolCounts(Deck $deck): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $token = $this->bearerToken();
        if (null === $token) {
            return null;
        }

        $cacheKey = 'sealed_pool_by_deck_'.md5($user->getKeycloakId().'|'.$deck->getId());

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($token, $deck) {
            $url = $this->alteredDraftUrl.'/api/tournament-pool-by-deck?deckId='.urlencode((string) $deck->getId());

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['Authorization' => 'Bearer '.$token],
                ]);
                if (200 !== $response->getStatusCode()) {
                    $item->expiresAfter(self::ERROR_TTL);

                    return null;
                }
                $data = $response->toArray();
            } catch (\Throwable) {
                $item->expiresAfter(self::ERROR_TTL);

                return null;
            }

            $item->expiresAfter(self::CACHE_TTL);

            return $data['cards'] ?? null;
        });
    }

    private function bearerToken(): ?string
    {
        $header = $this->requestStack->getCurrentRequest()?->headers->get('Authorization') ?? '';

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }
}
