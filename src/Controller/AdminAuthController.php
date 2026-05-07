<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\KeycloakJwtDecoder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AdminAuthController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly KeycloakJwtDecoder  $jwtDecoder,
        private readonly UserRepository      $userRepository,
        #[Autowire('%keycloak_base_url%')]   private readonly string $keycloakBaseUrl,
        #[Autowire('%keycloak_realm%')]      private readonly string $keycloakRealm,
        #[Autowire('%keycloak_client_id%')]  private readonly string $keycloakClientId,
        #[Autowire('%keycloak_client_secret%')] private readonly string $keycloakClientSecret,
    ) {}

    #[Route('/admin/login', name: 'admin_login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        $verifier  = $this->generateVerifier();
        $challenge = $this->generateChallenge($verifier);

        $request->getSession()->set('pkce_verifier', $verifier);

        $params = http_build_query([
            'client_id'             => $this->keycloakClientId,
            'redirect_uri'          => $this->callbackUri($request),
            'response_type'         => 'code',
            'scope'                 => 'openid',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return $this->redirect(
            sprintf('%s/realms/%s/protocol/openid-connect/auth?%s', $this->keycloakBaseUrl, $this->keycloakRealm, $params)
        );
    }

    #[Route('/admin/callback', name: 'admin_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $code     = $request->query->get('code');
        $verifier = $request->getSession()->get('pkce_verifier');
        $request->getSession()->remove('pkce_verifier');

        if (!$code || !$verifier) {
            return $this->redirectToRoute('admin_login');
        }

        $body = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->keycloakClientId,
            'code'          => $code,
            'redirect_uri'  => $this->callbackUri($request),
            'code_verifier' => $verifier,
        ];

        if ($this->keycloakClientSecret !== '') {
            $body['client_secret'] = $this->keycloakClientSecret;
        }

        $response = $this->httpClient->request('POST',
            sprintf('%s/realms/%s/protocol/openid-connect/token', $this->keycloakBaseUrl, $this->keycloakRealm),
            ['body' => $body]
        );

        $data = $response->toArray(false);

        if (isset($data['error'])) {
            return new Response(
                sprintf('Erreur Keycloak : %s — %s', $data['error'], $data['error_description'] ?? ''),
                Response::HTTP_UNAUTHORIZED,
            );
        }

        try {
            $decoded = $this->jwtDecoder->decode($data['access_token']);
        } catch (\Throwable $e) {
            return new Response('Token invalide : ' . $e->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        $user = $this->userRepository->findByKeycloakId($decoded->sub);

        if ($user === null || !$user->isAdmin()) {
            return new Response('Accès refusé.', Response::HTTP_FORBIDDEN);
        }

        $request->getSession()->set('admin_user_id', $user->getId()->toRfc4122());

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/admin/logout', name: 'admin_logout', methods: ['GET'])]
    public function logout(Request $request): Response
    {
        $request->getSession()->invalidate();

        $logoutUrl = sprintf(
            '%s/realms/%s/protocol/openid-connect/logout?post_logout_redirect_uri=%s&client_id=%s',
            $this->keycloakBaseUrl,
            $this->keycloakRealm,
            urlencode($request->getSchemeAndHttpHost() . '/admin/login'),
            $this->keycloakClientId,
        );

        return $this->redirect($logoutUrl);
    }

    private function callbackUri(Request $request): string
    {
        return $request->getSchemeAndHttpHost() . '/admin/callback';
    }

    private function generateVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function generateChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
