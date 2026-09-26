<?php

namespace App\Tests\Api;

use App\Entity\FrontierPool;
use App\Tests\Support\FrontierFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Deck save / read with the Frontier pool, and the full lifecycle across a pool switch.
 */
class FrontierPoolDeckTest extends WebTestCase
{
    private const array POOL_A = ['AX-020-U-101', 'BR-003-U-7'];
    private const array POOL_B = ['BR-003-U-7', 'LY-011-U-42'];

    private KernelBrowser $client;
    private MockHttpClient $alteredCoreMock;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->alteredCoreMock = static::getContainer()->get('altered_core.mock_http_client');
    }

    private function servePool(array $slugs, bool $uniqueInFrontier): void
    {
        $this->alteredCoreMock->setResponseFactory(FrontierFixtures::alteredCore(FrontierFixtures::cards($uniqueInFrontier), $slugs));
    }

    private function storePool(array $slugs): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pool = new FrontierPool(id: FrontierPool::fingerprint($slugs), cardCount: count($slugs), revision: 1, now: new \DateTimeImmutable());
        $em->persist($pool);
        $em->flush();

        return $pool->getId();
    }

    private function request(string $method, string $uri, ?array $body = null): array
    {
        $token = JWT::encode([
            'sub' => 'user-frontier-pool',
            'preferred_username' => 'testuser',
            'email' => 'test@test.com',
            'iss' => 'dev',
            'iat' => time(),
            'exp' => time() + 3600,
        ], '$ecretf0rt3st_extended_for_hs256_tests', 'HS256');

        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json',
        ], null === $body ? null : json_encode($body));

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function postDeck(string $format, bool $isDraft = false): array
    {
        return $this->request('POST', '/api/decks', [
            'name' => 'Frontier deck',
            'format' => $format,
            'isDraft' => $isDraft,
            'deckCards' => FrontierFixtures::deckCards(),
        ]);
    }

    private function syncPool(): CommandTester
    {
        $tester = new CommandTester((new Application($this->client->getKernel()))->find('app:frontier:sync-pool'));
        $tester->execute([]);

        return $tester;
    }

    public function testFrontierDeckIsStampedWithTheCurrentPoolOnSaveAndRead(): void
    {
        $poolA = $this->storePool(self::POOL_A);
        $this->servePool(self::POOL_A, uniqueInFrontier: true);

        $deck = $this->postDeck('frontier');

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($deck['legal']);
        self::assertSame($poolA, $deck['frontierPool']);

        $read = $this->request('GET', '/api/decks/'.$deck['id']);
        self::assertSame($poolA, $read['frontierPool']);

        $list = $this->request('GET', '/api/decks');
        self::assertSame($poolA, $list[0]['frontierPool']);
    }

    public function testFrontierDeckSavedBeforeAnyPoolIsKnownHasNullPool(): void
    {
        $this->servePool(self::POOL_A, uniqueInFrontier: true);

        $deck = $this->postDeck('frontier');

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($deck['legal']);
        // Null fields are omitted from responses (API Platform skip_null_values), like formatErrors.
        self::assertArrayNotHasKey('frontierPool', $deck);
    }

    public function testNonFrontierAndDraftDecksHaveNullPool(): void
    {
        $this->storePool(self::POOL_A);
        $this->servePool(self::POOL_A, uniqueInFrontier: true);

        $standard = $this->postDeck('standard');
        $draft = $this->postDeck('frontier', isDraft: true);

        self::assertTrue($standard['legal']);
        self::assertArrayNotHasKey('frontierPool', $standard);
        self::assertFalse($draft['legal']);
        self::assertArrayNotHasKey('frontierPool', $draft);
    }

    public function testSwitchingAFrontierDeckToStandardDropsItsPool(): void
    {
        $this->storePool(self::POOL_A);
        $this->servePool(self::POOL_A, uniqueInFrontier: true);
        $deck = $this->postDeck('frontier');

        $updated = $this->request('PATCH', '/api/decks/'.$deck['id'], ['format' => 'standard']);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('frontierPool', $updated);
    }

    public function testDeckSavedBeforeAPoolSwitchIsRevalidatedAgainstTheNewPool(): void
    {
        $poolA = $this->storePool(self::POOL_A);
        $this->servePool(self::POOL_A, uniqueInFrontier: true);
        $deck = $this->postDeck('frontier');
        self::assertTrue($deck['legal']);

        // cards-api imports pool B: the deck's Unique is no longer tagged FRONTIER.
        // The card payload cached by the save above must not be reused.
        $this->servePool(self::POOL_B, uniqueInFrontier: false);

        $staleRead = $this->request('GET', '/api/decks/'.$deck['id']);
        self::assertTrue($staleRead['legal']);
        self::assertSame($poolA, $staleRead['frontierPool']);

        $tester = $this->syncPool();
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $poolB = FrontierPool::fingerprint(self::POOL_B);
        $read = $this->request('GET', '/api/decks/'.$deck['id']);
        self::assertFalse($read['legal']);
        self::assertFalse($read['legalityDetail']['frontierUniques']);
        self::assertSame($poolB, $read['frontierPool']);

        $formats = $this->request('GET', '/api/formats');
        $frontier = array_values(array_filter($formats, static fn (array $f): bool => 'frontier' === $f['code']))[0];
        self::assertSame($poolB, $frontier['pool']['id']);
    }

    public function testDeckEditedAfterAPoolSwitchIsStampedWithTheNewPool(): void
    {
        $this->storePool(self::POOL_A);
        $this->servePool(self::POOL_A, uniqueInFrontier: true);
        $deck = $this->postDeck('frontier');

        $this->servePool(self::POOL_B, uniqueInFrontier: false);
        $this->syncPool();

        $updated = $this->request('PATCH', '/api/decks/'.$deck['id'], ['name' => 'Renamed']);

        self::assertResponseIsSuccessful();
        self::assertFalse($updated['legal']);
        self::assertSame(FrontierPool::fingerprint(self::POOL_B), $updated['frontierPool']);
    }
}
