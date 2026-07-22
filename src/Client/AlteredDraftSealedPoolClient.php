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
 * (GET /api/sealed-pool), for SealedFormatValidator's pool-membership check.
 *
 * Chosen over calling altered-draft's /api/validate-deck on every deck save (the
 * other option considered): this fetches the pool ONCE per player and caches it
 * until the tournament event's `ends_at`, so altered-draft sees one call per player
 * per event instead of one per deck save. Cached by the resolved Keycloak sub (not
 * the raw bearer token — the token rotates well before `ends_at`, which would
 * otherwise defeat the caching entirely).
 *
 * Forwards the caller's own bearer token to altered-draft, which verifies it against
 * the SAME Keycloak realm (`auth.altered.re/realms/players`) this app authenticates
 * against — so a caller can only ever fetch their OWN pool, same trust model as every
 * other endpoint here.
 */
readonly class AlteredDraftSealedPoolClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private Security $security,
        private RequestStack $requestStack,
        private string $alteredDraftUrl,
    ) {
    }

    /**
     * Returns reference => available quantity for the current user's sealed pool, or
     * null if there's no authenticated user, no active tournament event, or the call
     * to altered-draft fails.
     *
     * @return array<string, int>|null
     */
    public function getPoolCounts(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $cacheKey = 'sealed_pool_'.md5($user->getKeycloakId());

        return $this->cache->get($cacheKey, function (ItemInterface $item) {
            $token = $this->bearerToken();
            if (null === $token) {
                $item->expiresAfter(10);

                return null;
            }

            try {
                $response = $this->httpClient->request('GET', $this->alteredDraftUrl.'/api/sealed-pool', [
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

            $endsAt = isset($data['event']['ends_at']) ? new \DateTimeImmutable($data['event']['ends_at']) : null;
            $item->expiresAfter($endsAt ? max(30, $endsAt->getTimestamp() - time()) : 30);

            return $data['pool'] ?? null;
        });
    }

    private function bearerToken(): ?string
    {
        $header = $this->requestStack->getCurrentRequest()?->headers->get('Authorization') ?? '';

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }
}
