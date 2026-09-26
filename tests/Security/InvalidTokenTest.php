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

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        /** @var MockHttpClient $alteredCoreMock */
        $alteredCoreMock = static::getContainer()->get('altered_core.mock_http_client');
        $alteredCoreMock->setResponseFactory(
            static fn (): MockResponse => new MockResponse('[]', ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']])
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     */
    private static function makeToken(string $sub, array $overrides = [], string $secret = self::SECRET): string
    {
        return JWT::encode(array_merge([
            'sub' => $sub,
            'preferred_username' => 'testuser',
            'email' => 'test@example.com',
            'iss' => 'dev',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $overrides), $secret, 'HS256');
    }

    /**
     * Each value is the full Authorization header of a request that must be treated as
     * anonymous on public routes.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidAuthorizationHeaders(): iterable
    {
        yield 'expired token' => ['Bearer '.self::makeToken('invalid-expired', ['iat' => time() - 7200, 'exp' => time() - 3600])];
        yield 'bad signature' => ['Bearer '.self::makeToken('invalid-signature', [], 'another_secret_long_enough_for_hs256_signing')];
        yield 'token without sub claim' => ['Bearer '.self::makeToken('', ['sub' => null])];
        yield 'malformed token (production repro)' => ['Bearer abc.def.ghi'];
        yield 'malformed dev token' => ['Bearer eyJhbGciOiJIUzI1NiJ9.'.rtrim(strtr(base64_encode('{"iss":"dev","sub":"x"}'), '+/', '-_'), '=').'.not-a-signature'];
        yield 'empty bearer token' => ['Bearer '];
        yield 'valid token without Bearer prefix' => [self::makeToken('invalid-no-prefix')];
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
        $deck = $this->request('POST', '/api/decks', 'Bearer '.self::makeToken($owner), (string) json_encode(['name' => 'Deck of '.$owner, 'isDraft' => false]));
        self::assertResponseStatusCodeSame(201);

        if ($isPublic) {
            $this->request('PATCH', '/api/decks/'.$deck['id'], 'Bearer '.self::makeToken($owner), (string) json_encode(['isPublic' => true]), 'application/merge-patch+json');
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
        $result = $this->request('POST', '/api/decks/'.$id.'/upvote', 'Bearer '.self::makeToken($voter));
        self::assertResponseIsSuccessful();
        self::assertTrue($result['hasUpvoted']);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function findInPublicList(string $deckId, ?string $authorization): array
    {
        $data = $this->request('GET', '/api/decks/public?itemsPerPage=1000', $authorization);
        self::assertResponseStatusCodeSame(200);

        foreach ($data['member'] as $deck) {
            if ($deck['id'] === $deckId) {
                return $deck;
            }
        }
        self::fail(sprintf('Deck "%s" not found in the public list.', $deckId));
    }

    // ── GET /api/decks/public ─────────────────────────────────────────────────

    public function testPublicListWithoutTokenReturnsHasUpvotedFalse(): void
    {
        $id = $this->createDeckUpvotedBy('voter-'.__FUNCTION__);

        self::assertFalse($this->findInPublicList($id, null)['hasUpvoted']);
    }

    public function testPublicListWithValidTokenReturnsCallerHasUpvoted(): void
    {
        $voter = 'voter-'.__FUNCTION__;
        $id = $this->createDeckUpvotedBy($voter);

        self::assertTrue($this->findInPublicList($id, 'Bearer '.self::makeToken($voter))['hasUpvoted']);
    }

    #[DataProvider('invalidAuthorizationHeaders')]
    public function testPublicListWithInvalidTokenIsAnonymous(string $authorization): void
    {
        $id = $this->createDeckUpvotedBy('voter-'.__FUNCTION__.'-'.md5($authorization));

        $data = $this->request('GET', '/api/decks/public?itemsPerPage=1000', $authorization);

        self::assertResponseStatusCodeSame(200);
        self::assertNotEmpty($data['member']);
        foreach ($data['member'] as $deck) {
            self::assertFalse($deck['hasUpvoted'], sprintf('Deck "%s" must not be marked upvoted for an anonymous caller.', $deck['id']));
        }
        self::assertContains($id, array_column($data['member'], 'id'));
    }

    /**
     * The upvote was made by the owner of the token, but the token is expired: the list
     * must not leak it through hasUpvoted.
     */
    public function testPublicListWithExpiredTokenOfVoterReturnsHasUpvotedFalse(): void
    {
        $voter = 'voter-'.__FUNCTION__;
        $id = $this->createDeckUpvotedBy($voter);

        $expired = 'Bearer '.self::makeToken($voter, ['iat' => time() - 7200, 'exp' => time() - 3600]);

        self::assertFalse($this->findInPublicList($id, $expired)['hasUpvoted']);
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

        $this->request('GET', '/api/decks/'.$privateDeckId, 'Bearer '.self::makeToken($owner, ['iat' => time() - 7200, 'exp' => time() - 3600]));
        self::assertResponseStatusCodeSame(401);

        $this->request('GET', '/api/decks/'.$privateDeckId, 'Bearer '.self::makeToken($owner));
        self::assertResponseStatusCodeSame(200);
    }

    // ── Protected routes ──────────────────────────────────────────────────────

    #[DataProvider('invalidAuthorizationHeaders')]
    public function testProtectedRoutesStillRejectInvalidToken(string $authorization): void
    {
        $owner = 'owner-'.__FUNCTION__.'-'.md5($authorization);
        $publicDeckId = $this->createDeck($owner, true);

        $routes = [
            ['GET', '/api/decks', null, 'application/json'],
            ['POST', '/api/decks', (string) json_encode(['name' => 'Nope']), 'application/json'],
            ['PATCH', '/api/decks/'.$publicDeckId, (string) json_encode(['name' => 'Hijacked']), 'application/merge-patch+json'],
            ['DELETE', '/api/decks/'.$publicDeckId, null, 'application/json'],
            ['POST', '/api/decks/'.$publicDeckId.'/upvote', null, 'application/json'],
            ['GET', '/api/me', null, 'application/json'],
            ['GET', '/api/bga/decks', null, 'application/json'],
            ['GET', '/api/admin/stats', null, 'application/json'],
        ];

        foreach ($routes as [$method, $uri, $body, $contentType]) {
            $this->request($method, $uri, $authorization, $body, $contentType);
            self::assertResponseStatusCodeSame(401, sprintf('%s %s must answer 401 with an invalid token.', $method, $uri));
        }

        // The deck was neither modified nor deleted.
        $deck = $this->request('GET', '/api/decks/'.$publicDeckId);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Deck of '.$owner, $deck['name']);
        self::assertSame(0, $deck['upvoteCount']);
    }
}
