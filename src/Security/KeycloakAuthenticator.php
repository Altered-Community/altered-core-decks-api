<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class KeycloakAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly UserRepository         $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface    $httpClient,
        private readonly CacheInterface         $cache,
        private readonly string                 $keycloakBaseUrl,
        private readonly string                 $keycloakRealm,
        private readonly string                 $appSecret,
        private readonly string                 $appEnv,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization')
            && str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $token = substr($request->headers->get('Authorization'), 7);

        try {
            $decoded = $this->decodeToken($token);
        } catch (\Throwable $e) {
            throw new AuthenticationException('Invalid token: ' . $e->getMessage());
        }

        $keycloakId = $decoded->sub ?? null;
        if (!$keycloakId) {
            throw new AuthenticationException('Token missing sub claim.');
        }

        return new SelfValidatingPassport(
            new UserBadge($keycloakId, function (string $keycloakId) use ($decoded): User {
                return $this->findOrCreateUser($keycloakId, $decoded);
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(['error' => $exception->getMessageKey()], Response::HTTP_UNAUTHORIZED);
    }

    private function findOrCreateUser(string $keycloakId, object $decoded): User
    {
        $user = $this->userRepository->findByKeycloakId($keycloakId);

        if (!$user) {
            $user = new User();
            $user->setKeycloakId($keycloakId);
            $this->em->persist($user);
        }

        $user->setEmail($decoded->email ?? null);
        $user->setUsername($decoded->preferred_username ?? $decoded->name ?? null);
        $user->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();

        return $user;
    }

    private function decodeToken(string $token): object
    {
        // Dev mode: accept local HS256 tokens (issuer = "dev")
        if ($this->appEnv === 'dev') {
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

            $url      = sprintf('%s/realms/%s/protocol/openid-connect/certs', $this->keycloakBaseUrl, $this->keycloakRealm);
            $response = $this->httpClient->request('GET', $url);

            return $response->toArray();
        });
    }
}
