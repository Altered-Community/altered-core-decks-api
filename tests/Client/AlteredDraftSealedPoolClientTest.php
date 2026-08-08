<?php

namespace App\Tests\Client;

use App\Client\AlteredDraftSealedPoolClient;
use App\Entity\Deck;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class AlteredDraftSealedPoolClientTest extends TestCase
{
    private function security(User $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function requestStack(string $token): RequestStack
    {
        $stack = new RequestStack();
        $stack->push(new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]));

        return $stack;
    }

    private function user(string $sub = 'sub-1'): User
    {
        $user = new User();
        $user->setKeycloakId($sub);

        return $user;
    }

    public function testReturnsNullWithoutAuthenticatedUser(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $client = new AlteredDraftSealedPoolClient(
            new MockHttpClient(),
            new ArrayAdapter(),
            $security,
            $this->requestStack('token'),
            'https://altered-draft.altered.re',
        );

        self::assertNull($client->getPoolCounts(new Deck()));
    }

    public function testReturnsNullWithoutBearerToken(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request());

        $client = new AlteredDraftSealedPoolClient(
            new MockHttpClient(),
            new ArrayAdapter(),
            $this->security($this->user()),
            $stack,
            'https://altered-draft.altered.re',
        );

        self::assertNull($client->getPoolCounts(new Deck()));
    }

    public function testFetchesPoolByDeckId(): void
    {
        $deckId = null;
        $httpClient = new MockHttpClient(
            function (string $method, string $url) use (&$deckId) {
                self::assertStringContainsString('/api/tournament-pool-by-deck?deckId='.$deckId, $url);

                return new MockResponse(json_encode(['id' => 'pool-uuid-1', 'cards' => ['ALT_EOLE_B_AX_1_C' => 3]]), [
                    'http_code' => 200,
                    'response_headers' => ['Content-Type: application/json'],
                ]);
            }
        );

        $client = new AlteredDraftSealedPoolClient(
            $httpClient,
            new ArrayAdapter(),
            $this->security($this->user()),
            $this->requestStack('tok'),
            'https://altered-draft.altered.re',
        );

        $deck = new Deck();
        $deckId = (string) $deck->getId();

        self::assertSame(['ALT_EOLE_B_AX_1_C' => 3], $client->getPoolCounts($deck));
    }

    public function testResultIsCachedPerUserAndDeck(): void
    {
        $calls = 0;
        $httpClient = new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse(json_encode(['cards' => ['ALT_EOLE_B_AX_1_C' => 1]]), [
                'http_code' => 200,
                'response_headers' => ['Content-Type: application/json'],
            ]);
        });

        $client = new AlteredDraftSealedPoolClient(
            $httpClient,
            new ArrayAdapter(),
            $this->security($this->user()),
            $this->requestStack('tok'),
            'https://altered-draft.altered.re',
        );

        $deck = new Deck();

        $client->getPoolCounts($deck);
        $client->getPoolCounts($deck);

        self::assertSame(1, $calls);
    }

    public function testNoLinkedPoolFailsClosed(): void
    {
        $httpClient = new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 404]));

        $client = new AlteredDraftSealedPoolClient(
            $httpClient,
            new ArrayAdapter(),
            $this->security($this->user()),
            $this->requestStack('tok'),
            'https://altered-draft.altered.re',
        );

        self::assertNull($client->getPoolCounts(new Deck()));
    }

    public function testUnreachableAlteredDraftFailsClosed(): void
    {
        $httpClient = new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 500]));

        $client = new AlteredDraftSealedPoolClient(
            $httpClient,
            new ArrayAdapter(),
            $this->security($this->user()),
            $this->requestStack('tok'),
            'https://altered-draft.altered.re',
        );

        self::assertNull($client->getPoolCounts(new Deck()));
    }
}
