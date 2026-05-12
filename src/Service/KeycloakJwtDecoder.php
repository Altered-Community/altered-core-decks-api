<?php

namespace App\Service;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class KeycloakJwtDecoder
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface      $cache,
        private string              $keycloakBaseUrl,
        private string              $keycloakRealm,
        private string              $appSecret,
        private bool                $devAuthEnabled,
    ) {}

    public function decode(string $token): object
    {
        if ($this->devAuthEnabled) {
            $parts   = explode('.', $token);
            $payload = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
            if (($payload['iss'] ?? null) === 'dev') {
                return JWT::decode($token, new Key($this->appSecret, 'HS256'));
            }
        }

        return JWT::decode($token, JWK::parseKeySet($this->getJwks()));
    }

    private function getJwks(): array
    {
        return $this->cache->get('keycloak_jwks', function (ItemInterface $item) {
            $item->expiresAfter(3600);
            $url = sprintf('%s/realms/%s/protocol/openid-connect/certs', $this->keycloakBaseUrl, $this->keycloakRealm);
            return $this->httpClient->request('GET', $url)->toArray();
        });
    }
}
