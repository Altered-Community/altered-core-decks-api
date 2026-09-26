<?php

namespace App\Tests\Security;

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * An invalid bearer token is ignored on PUBLIC_ACCESS routes (the request continues
 * as anonymous) and still answers 401 everywhere else.
 */
class InvalidTokenTest extends WebTestCase
{
    private const SECRET = '$ecretf0rt3st_extended_for_hs256_tests';

    /** Public RSA key set served as Keycloak's JWKS, so non-dev tokens go through real signature checks. */
    private const JWKS = '{"keys":[{"kty":"RSA","kid":"test","use":"sig","alg":"RS256","n":"0wDIF_uzYxlRmF-mp3mDnCUqAwoA8rhyJ4Z02b7Wg6WGC4mDpGjSEUD6uFQhHYCkc6IJKf4aX-UmrbVyeIZJBdTR1whnaxzX6xpwhvlj7veM0xuaSvpFX38NLaTF05WoGE83YJBVcln1QyFNqgD1Hzks86jKo7v2J2MmVWPKelHNg7nvYslk2xrHII6rV5u3EKtAB-OyXoVejMd1OodCVGNZEOFW1P_hsUAcO5Pt0SNruDF0imD0i2zBK-NTG6XeIXCQE6uO1_RX5WAKKphUq6KKTG--yg0dMpdRClsviFZAfKU_H2oqT-Ia8d5FgPIUDpS0megvorsKzpsJc9WyhQ","e":"AQAB"}]}';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $json = static fn (string $body): MockResponse => new MockResponse($body, ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']]);

        /** @var MockHttpClient $alteredCoreMock */
        $alteredCoreMock = static::getContainer()->get('altered_core.mock_http_client');
        $alteredCoreMock->setResponseFactory(static fn (): MockResponse => $json('[]'));

        /** @var MockHttpClient $keycloakMock */
        $keycloakMock = static::getContainer()->get('keycloak.mock_http_client');
        $keycloakMock->setResponseFactory(static fn (): MockResponse => $json(self::JWKS));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     */
    private static function bearer(string $sub, array $overrides = [], string $secret = self::SECRET): string
    {
        return 'Bearer '.JWT::encode(array_merge([
            'sub' => $sub,
            'preferred_username' => 'testuser',
            'email' => 'test@example.com',
            'iss' => 'dev',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $overrides), $secret, 'HS256');
    }

    private static function expiredBearer(string $sub): string
    {
        return self::bearer($sub, ['iat' => time() - 7200, 'exp' => time() - 3600]);
    }

    /**
     * Each value is the full Authorization header of a request that must be treated as
     * anonymous on public routes.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidAuthorizationHeaders(): iterable
    {
        yield 'expired token' => [self::expiredBearer('invalid-expired')];
        yield 'bad signature' => [self::bearer('invalid-signature', [], 'another_secret_long_enough_for_hs256_signing')];
        yield 'token without sub claim' => [self::bearer('', ['sub' => null])];
        yield 'malformed token (production repro)' => ['Bearer abc.def.ghi'];
        yield 'malformed dev token' => ['Bearer eyJhbGciOiJIUzI1NiJ9.'.rtrim(strtr(base64_encode('{"iss":"dev","sub":"x"}'), '+/', '-_'), '=').'.not-a-signature'];
        yield 'empty bearer token' => ['Bearer '];
        yield 'valid token without Bearer prefix' => [substr(self::bearer('invalid-no-prefix'), 7)];
        yield 'other scheme' => ['Basic dXNlcjpwYXNz'];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $uri, ?string $authorization = null, ?string $body = null, string $contentType = 'application/json'): array
    {
        $headers = ['CONTENT_TYPE' => $contentType];
        if (null !== $authorization) {
            $headers['HTTP_AUTHORIZATION'] = $authorization;
        }

        $this->client->request($method, $uri, [], [], $headers, $body);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * Creates a deck owned by $owner (public or not) and returns its id.
     */
    private function createDeck(string $owner, bool $isPublic): string
    {
        $deck = $this->request('POST', '/api/decks', self::bearer($owner), (string) json_encode(['name' => 'Deck of '.$owner, 'isDraft' => false]));
        self::assertResponseStatusCodeSame(201);

        if ($isPublic) {
            $this->request('PATCH', '/api/decks/'.$deck['id'], self::bearer($owner), (string) json_encode(['isPublic' => true]), 'application/merge-patch+json');
            self::assertResponseIsSuccessful();
        }

        return $deck['id'];
    }

    /**
     * Creates a public deck upvoted by $voter and returns its id.
     */
    private function createDeckUpvotedBy(string $voter): string
    {
        $id = $this->createDeck('owner-'.$voter, true);
        $result = $this->request('POST', '/api/decks/'.$id.'/upvote', self::bearer($voter));
        self::assertResponseIsSuccessful();
        self::assertTrue($result['hasUpvoted']);

        return $id;
    }

    /**
     * Returns the public list as `deck id => hasUpvoted`, after asserting a 200.
     *
     * @return array<string, bool>
     */
    private function publicListHasUpvoted(?string $authorization): array
    {
        $data = $this->request('GET', '/api/decks/public?itemsPerPage=1000', $authorization);
        self::assertResponseStatusCodeSame(200);

        return array_column($data['member'], 'hasUpvoted', 'id');
    }

    // ── GET /api/decks/public ─────────────────────────────────────────────────

    // Control cases: same scenarios as DeckTest, kept here so the token matrix is complete.

    public function testPublicListWithoutTokenReturnsHasUpvotedFalse(): void
    {
        $id = $this->createDeckUpvotedBy('voter-'.__FUNCTION__);

        self::assertFalse($this->publicListHasUpvoted(null)[$id]);
    }

    public function testPublicListWithValidTokenReturnsCallerHasUpvoted(): void
    {
        $voter = 'voter-'.__FUNCTION__;
        $id = $this->createDeckUpvotedBy($voter);

        self::assertTrue($this->publicListHasUpvoted(self::bearer($voter))[$id]);
    }

    #[DataProvider('invalidAuthorizationHeaders')]
    public function testPublicListWithInvalidTokenIsAnonymous(string $authorization): void
    {
        $id = $this->createDeckUpvotedBy('voter-'.__FUNCTION__.'-'.md5($authorization));

        $hasUpvoted = $this->publicListHasUpvoted($authorization);

        self::assertArrayHasKey($id, $hasUpvoted);
        self::assertNotContains(true, $hasUpvoted, 'No deck may be marked upvoted for an anonymous caller.');
    }

    /**
     * The upvote was made by the owner of the token, but the token is expired: the list
     * must not leak it through hasUpvoted.
     */
    public function testPublicListWithExpiredTokenOfVoterReturnsHasUpvotedFalse(): void
    {
        $voter = 'voter-'.__FUNCTION__;
        $id = $this->createDeckUpvotedBy($voter);

        self::assertFalse($this->publicListHasUpvoted(self::expiredBearer($voter))[$id]);
    }

    // ── Other public routes ───────────────────────────────────────────────────

    #[DataProvider('invalidAuthorizationHeaders')]
    public function testOtherPublicRoutesIgnoreInvalidToken(string $authorization): void
    {
        $publicDeckId = $this->createDeck('owner-'.__FUNCTION__.'-'.md5($authorization), true);

        $this->request('GET', '/api/formats', $authorization);
        self::assertResponseStatusCodeSame(200);

        $this->request('GET', '/api/decks/public/heroes', $authorization);
        self::assertResponseStatusCodeSame(200);

        $deck = $this->request('GET', '/api/decks/'.$publicDeckId, $authorization);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($publicDeckId, $deck['id']);

        $this->request('GET', '/api/bga/decks/'.$publicDeckId, $authorization);
        self::assertResponseStatusCodeSame(200);
    }

    public function testPrivateDeckWithInvalidTokenReturns401(): void
    {
        $owner = 'owner-'.__FUNCTION__;
        $privateDeckId = $this->createDeck($owner, false);

        $this->request('GET', '/api/decks/'.$privateDeckId, self::expiredBearer($owner));
        self::assertResponseStatusCodeSame(401);

        $this->request('GET', '/api/decks/'.$privateDeckId, self::bearer($owner));
        self::assertResponseStatusCodeSame(200);
    }

    // ── Protected routes ──────────────────────────────────────────────────────

    #[DataProvider('invalidAuthorizationHeaders')]
    public function testProtectedRoutesStillRejectInvalidToken(string $authorization): void
    {
        $owner = 'owner-'.__FUNCTION__.'-'.md5($authorization);
        $publicDeckId = $this->createDeck($owner, true);

        $routes = [
            ['GET', '/api/decks'],
            ['POST', '/api/decks', (string) json_encode(['name' => 'Nope'])],
            ['PATCH', '/api/decks/'.$publicDeckId, (string) json_encode(['name' => 'Hijacked']), 'application/merge-patch+json'],
            ['DELETE', '/api/decks/'.$publicDeckId],
            ['POST', '/api/decks/'.$publicDeckId.'/upvote'],
            ['GET', '/api/me'],
            ['GET', '/api/bga/decks'],
            ['GET', '/api/admin/stats'],
        ];

        foreach ($routes as $route) {
            [$method, $uri] = $route;
            $this->request($method, $uri, $authorization, $route[2] ?? null, $route[3] ?? 'application/json');
            self::assertResponseStatusCodeSame(401, sprintf('%s %s must answer 401 with an invalid token.', $method, $uri));
        }

        // The deck was neither modified nor deleted.
        $deck = $this->request('GET', '/api/decks/'.$publicDeckId);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Deck of '.$owner, $deck['name']);
        self::assertSame(0, $deck['upvoteCount']);
    }
}
