<?php

namespace App\Client;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches the current player's tournament sealed pool from altered-draft
 * (GET /api/tournament-pool-counts), for SealedFormatValidator's pool-membership check.
 *
 * `tournamentSeed` is read from the CURRENT REQUEST's query string, not passed as a
 * parameter — DeckFormatValidatorInterface::validate() has no room for extra context,
 * but this client already injects RequestStack (for the bearer token below), so it can
 * just read `?tournamentSeed=` itself. altered-bga-api's DeckContentHandler forwards
 * it as a query param on the deck-content call (GET /api/bga/decks/{id}) exactly like
 * it forwards eventFormat/tableId, so BgaDeckController::item() sees it there for free;
 * the normal deck-save flow (POST/PATCH /api/decks) never has it, so it naturally
 * resolves to the player's own normal (casual) pool instead — no special-casing needed
 * between the two call sites.
 *
 * Chosen over calling altered-draft's /api/tournament-validate-deck with the whole
 * candidate deck on every save (the other option considered): this fetches the pool
 * ONCE per (player, tournamentSeed) and caches it, so altered-draft sees one call
 * instead of one per deck save/BGA fetch. A bound tournament pool is immutable forever
 * once bound (nonce + seed never change), so it's cached indefinitely; a normal-mode
 * pool can be reset by the player at will, so it gets a short TTL instead. Cached by
 * the resolved Keycloak sub (not the raw bearer token — the token rotates far more
 * often than either cache lifetime, which would defeat the caching entirely).
 *
 * Forwards the caller's own bearer token to altered-draft, which verifies it against
 * the SAME Keycloak realm (`auth.altered.re/realms/players`) this app authenticates
 * against — so a caller can only ever fetch their OWN pool, same trust model as every
 * other endpoint here.
 */
readonly class AlteredDraftSealedPoolClient
{
    private const NORMAL_POOL_TTL = 60; // seconds — short-lived: the player can reset this pool anytime.
    private const TOURNAMENT_POOL_TTL = 31536000; // ~1 year — a bound pool is immutable forever.

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private Security $security,
        private RequestStack $requestStack,
        private string $alteredDraftUrl,
    ) {
    }

    /**
     * Returns reference => available quantity for the current user's sealed pool
     * (scoped to the current request's `tournamentSeed`, if any — see class docblock),
     * or null if there's no authenticated user or the call to altered-draft fails.
     *
     * @return array<string, int>|null
     */
    public function getPoolCounts(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $tournamentSeed = $this->tournamentSeed();
        $cacheKey = 'sealed_pool_'.md5($user->getKeycloakId().'|'.($tournamentSeed ?? ''));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($tournamentSeed) {
            $token = $this->bearerToken();
            if (null === $token) {
                $item->expiresAfter(10);

                return null;
            }

            $url = $this->alteredDraftUrl.'/api/tournament-pool-counts';
            if (null !== $tournamentSeed) {
                $url .= '?tournamentSeed='.urlencode($tournamentSeed);
            }

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['Authorization' => 'Bearer '.$token],
                ]);
                if (200 !== $response->getStatusCode()) {
                    $item->expiresAfter(30);

                    return null;
                }
                $data = $response->toArray();
            } catch (\Throwable) {
                $item->expiresAfter(30);

                return null;
            }

            $item->expiresAfter(null !== $tournamentSeed ? self::TOURNAMENT_POOL_TTL : self::NORMAL_POOL_TTL);

            return $data['cards'] ?? null;
        });
    }

    private function tournamentSeed(): ?string
    {
        $seed = $this->requestStack->getCurrentRequest()?->query->get('tournamentSeed');

        return is_string($seed) && '' !== $seed ? $seed : null;
    }

    private function bearerToken(): ?string
    {
        $header = $this->requestStack->getCurrentRequest()?->headers->get('Authorization') ?? '';

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }
}
