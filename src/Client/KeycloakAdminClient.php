<?php

namespace App\Client;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads user attributes through the Keycloak admin REST API with the client credentials grant.
 * The client needs "Service accounts" enabled and the realm-management `view-users` role.
 */
final class KeycloakAdminClient
{
    private ?string $accessToken = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%keycloak_base_url%')] private readonly string $keycloakBaseUrl,
        #[Autowire('%keycloak_realm%')] private readonly string $keycloakRealm,
        #[Autowire('%keycloak_client_id%')] private readonly string $keycloakClientId,
        #[Autowire('%keycloak_client_secret%')] private readonly string $keycloakClientSecret,
    ) {
    }

    /**
     * @return string|null the user's pseudo, or null when the user has none or no longer exists
     */
    public function fetchPseudo(string $keycloakId): ?string
    {
        $response = $this->requestUser($keycloakId);

        if (401 === $response->getStatusCode()) {
            $this->accessToken = null;
            $response = $this->requestUser($keycloakId);
        }

        if (404 === $response->getStatusCode()) {
            return null;
        }

        $pseudo = $response->toArray()['attributes']['pseudo'][0] ?? null;
        $pseudo = is_string($pseudo) ? trim($pseudo) : '';

        return '' !== $pseudo ? $pseudo : null;
    }

    private function requestUser(string $keycloakId): ResponseInterface
    {
        return $this->httpClient->request('GET', sprintf(
            '%s/admin/realms/%s/users/%s',
            $this->keycloakBaseUrl,
            rawurlencode($this->keycloakRealm),
            rawurlencode($keycloakId),
        ), ['auth_bearer' => $this->accessToken()]);
    }

    private function accessToken(): string
    {
        return $this->accessToken ??= $this->httpClient->request('POST', sprintf(
            '%s/realms/%s/protocol/openid-connect/token',
            $this->keycloakBaseUrl,
            rawurlencode($this->keycloakRealm),
        ), ['body' => [
            'grant_type' => 'client_credentials',
            'client_id' => $this->keycloakClientId,
            'client_secret' => $this->keycloakClientSecret,
        ]])->toArray()['access_token'];
    }
}
