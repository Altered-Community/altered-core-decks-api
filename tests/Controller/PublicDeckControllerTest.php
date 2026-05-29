<?php

namespace App\Tests\Controller;

use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PublicDeckControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private MockHttpClient $alteredCoreMock;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->alteredCoreMock = static::getContainer()->get('altered_core.mock_http_client');
        $this->alteredCoreMock->setResponseFactory(
            static fn (): MockResponse => new MockResponse('[]', ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']])
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeToken(string $sub): string
    {
        return JWT::encode([
            'sub' => $sub,
            'preferred_username' => 'testuser',
            'email' => 'test@example.com',
            'iss' => 'dev',
            'iat' => time(),
            'exp' => time() + 3600,
        ], '$ecretf0rt3st_extended_for_hs256_tests', 'HS256');
    }

    private function authHeaders(string $sub): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->makeToken($sub),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function createDeck(string $sub, array $body): array
    {
        $this->client->request('POST', '/api/decks', [], [], $this->authHeaders($sub), json_encode($body));

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * AlteredCore returns pre-localized strings (not locale maps) when ?locale=fr is given.
     * Passing an array to DeckCard::setName(?string) would throw a TypeError in PHP 8.
     */
    private function mockHeroCard(string $ref, string $name = 'Axiom Hero'): void
    {
        $card = [
            'reference' => $ref,
            'cardType' => ['reference' => 'HERO_MAIN'],
            'name' => $name,
            'imagePath' => '/img/hero.jpg',
            'faction' => ['code' => 'AX'],
        ];
        $json = json_encode([$card]);
        $this->alteredCoreMock->setResponseFactory(
            static fn (): MockResponse => new MockResponse($json, ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']])
        );
    }

    private function findHero(array $data, string $ref): array
    {
        foreach ($data as $hero) {
            if ($hero['reference'] === $ref) {
                return $hero;
            }
        }
        self::fail(sprintf('Hero "%s" not found in response.', $ref));
    }

    // ── GET /api/decks/public/heroes ──────────────────────────────────────────

    public function testHeroesEndpointReturns200(): void
    {
        $this->client->request('GET', '/api/decks/public/heroes');

        self::assertResponseIsSuccessful();
    }

    public function testHeroesEndpointReturnsArray(): void
    {
        $this->client->request('GET', '/api/decks/public/heroes');
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertIsArray($data);
    }

    public function testHeroesEndpointIsPublic(): void
    {
        $this->client->request('GET', '/api/decks/public/heroes');

        self::assertResponseStatusCodeSame(200);
    }

    public function testPublicDeckHeroAppearsInList(): void
    {
        $sub = 'pub-heroes-'.substr(__FUNCTION__, -10);
        $heroRef = 'ALT_CORE_B_AX_201_C';

        $this->mockHeroCard($heroRef);

        $this->createDeck($sub, [
            'name' => 'Public Hero Deck',
            'isPublic' => true,
            'deckCards' => [['cardReference' => $heroRef, 'quantity' => 1]],
        ]);

        $this->client->request('GET', '/api/decks/public/heroes');
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertContains($heroRef, array_column($data, 'reference'));
    }

    public function testPrivateDeckHeroDoesNotAppearInList(): void
    {
        $sub = 'pub-heroes-'.substr(__FUNCTION__, -10);
        $heroRef = 'ALT_CORE_B_AX_202_C';

        $this->mockHeroCard($heroRef);

        $this->createDeck($sub, [
            'name' => 'Private Hero Deck',
            'isPublic' => false,
            'deckCards' => [['cardReference' => $heroRef, 'quantity' => 1]],
        ]);

        $this->client->request('GET', '/api/decks/public/heroes');
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertNotContains($heroRef, array_column($data, 'reference'));
    }

    public function testDraftDeckHeroDoesNotAppearInList(): void
    {
        $sub = 'pub-heroes-'.substr(__FUNCTION__, -10);
        $heroRef = 'ALT_CORE_B_AX_203_C';

        $this->mockHeroCard($heroRef);

        $this->createDeck($sub, [
            'name' => 'Draft Hero Deck',
            'isPublic' => true,
            'isDraft' => true,
            'deckCards' => [['cardReference' => $heroRef, 'quantity' => 1]],
        ]);

        $this->client->request('GET', '/api/decks/public/heroes');
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertNotContains($heroRef, array_column($data, 'reference'));
    }

    public function testHeroIsDeduplicatedAcrossMultipleDecks(): void
    {
        $heroRef = 'ALT_CORE_B_AX_204_C';
        $this->mockHeroCard($heroRef);

        $this->createDeck('pub-heroes-dupe-a', [
            'name' => 'Deck A',
            'isPublic' => true,
            'deckCards' => [['cardReference' => $heroRef, 'quantity' => 1]],
        ]);
        $this->createDeck('pub-heroes-dupe-b', [
            'name' => 'Deck B',
            'isPublic' => true,
            'deckCards' => [['cardReference' => $heroRef, 'quantity' => 1]],
        ]);

        $this->client->request('GET', '/api/decks/public/heroes');
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(1, count(array_keys(array_column($data, 'reference'), $heroRef)));
    }

    public function testHeroEntryHasRequiredKeys(): void
    {
        $sub = 'pub-heroes-'.substr(__FUNCTION__, -10);
        $heroRef = 'ALT_CORE_B_AX_205_C';

        $this->mockHeroCard($heroRef);

        $this->createDeck($sub, [
            'name' => 'Shape Test Deck',
            'isPublic' => true,
            'deckCards' => [['cardReference' => $heroRef, 'quantity' => 1]],
        ]);

        $this->client->request('GET', '/api/decks/public/heroes');
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $hero = $this->findHero($data, $heroRef);

        self::assertArrayHasKey('reference', $hero);
        self::assertArrayHasKey('name', $hero);
        self::assertArrayHasKey('imagePath', $hero);
    }

    public function testHeroNameIsReturnedAsString(): void
    {
        $sub = 'pub-heroes-'.substr(__FUNCTION__, -10);
        $heroRef = 'ALT_CORE_B_AX_206_C';

        $this->mockHeroCard($heroRef, name: 'Axiom Hero');

        $this->createDeck($sub, [
            'name' => 'Name Test Deck',
            'isPublic' => true,
            'deckCards' => [['cardReference' => $heroRef, 'quantity' => 1]],
        ]);

        $this->client->request('GET', '/api/decks/public/heroes');
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame('Axiom Hero', $this->findHero($data, $heroRef)['name']);
    }

    public function testLocaleParameterIsAccepted(): void
    {
        $this->client->request('GET', '/api/decks/public/heroes?locale=en');

        self::assertResponseIsSuccessful();
        self::assertIsArray(json_decode($this->client->getResponse()->getContent(), true));
    }

    // ── GET /api/decks/public?faction= ───────────────────────────────────────

    public function testFactionFilterReturnsDeckMatchingFaction(): void
    {
        $axRef = 'ALT_CORE_B_AX_301_C';
        $this->mockHeroCard($axRef, 'Axiom Hero 301');
        $deck = $this->createDeck('faction-ax-301', [
            'name' => 'AX Deck 301',
            'isPublic' => true,
            'deckCards' => [['cardReference' => $axRef, 'quantity' => 1]],
        ]);

        $this->client->request('GET', '/api/decks/public?faction=AX');
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertResponseIsSuccessful();
        self::assertContains($deck['id'], array_column($data['member'], 'id'));
    }

    public function testFactionFilterExcludesOtherFactions(): void
    {
        // Only a LY deck exists in this transaction.
        $lyRef = 'ALT_CORE_B_LY_302_C';
        $this->mockHeroCard($lyRef, 'Lyra Hero 302');
        $lyDeck = $this->createDeck('faction-ly-302', [
            'name' => 'LY Deck 302',
            'isPublic' => true,
            'deckCards' => [['cardReference' => $lyRef, 'quantity' => 1]],
        ]);

        // LY filter must find the deck.
        $this->client->request('GET', '/api/decks/public?faction=LY');
        $lyData = json_decode($this->client->getResponse()->getContent(), true);
        self::assertContains($lyDeck['id'], array_column($lyData['member'], 'id'));

        // AX filter must not find the LY deck.
        $this->client->request('GET', '/api/decks/public?faction=AX');
        $axData = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotContains($lyDeck['id'], array_column($axData['member'], 'id'));
    }

    public function testFactionFilterWithNoMatchReturnsEmptyMember(): void
    {
        $this->client->request('GET', '/api/decks/public?faction=ZZZUNKNOWN');
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertResponseIsSuccessful();
        self::assertEmpty($data['member']);
        self::assertSame(0, $data['totalItems']);
    }

    public function testNoFactionFilterReturnsAllPublicDecks(): void
    {
        $lyRef = 'ALT_CORE_B_LY_303_C';
        $this->mockHeroCard($lyRef, 'Lyra Hero 303');
        $lyDeck = $this->createDeck('faction-ly-303', [
            'name' => 'LY Deck 303',
            'isPublic' => true,
            'deckCards' => [['cardReference' => $lyRef, 'quantity' => 1]],
        ]);

        $this->client->request('GET', '/api/decks/public');
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertContains($lyDeck['id'], array_column($data['member'], 'id'));
    }
}
