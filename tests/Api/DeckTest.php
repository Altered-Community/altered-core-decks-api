<?php

namespace App\Tests\Api;

use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DeckTest extends WebTestCase
{
    private KernelBrowser $client;
    private MockHttpClient $alteredCoreMock;

    protected function setUp(): void
    {
        $this->client          = static::createClient();
        $this->alteredCoreMock = static::getContainer()->get('altered_core.mock_http_client');
        // Default: return empty card list (deck with no cards never triggers HTTP call,
        // but this prevents MockHttpClient from throwing if called unexpectedly)
        $this->alteredCoreMock->setResponseFactory(
            new MockResponse('[]', ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']])
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeToken(string $sub): string
    {
        return JWT::encode([
            'sub'                => $sub,
            'preferred_username' => 'testuser',
            'email'              => 'test@test.com',
            'iss'                => 'dev',
            'iat'                => time(),
            'exp'                => time() + 3600,
        ], '$ecretf0rt3st', 'HS256');
    }

    private function authHeaders(string $sub): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->makeToken($sub),
            'CONTENT_TYPE'       => 'application/json',
        ];
    }

    private function post(string $sub, array $body): array
    {
        $this->client->request(
            'POST',
            '/api/decks',
            [],
            [],
            $this->authHeaders($sub),
            json_encode($body),
        );

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function patch(string $sub, string $id, array $body): array
    {
        $headers                  = $this->authHeaders($sub);
        $headers['CONTENT_TYPE']  = 'application/merge-patch+json';

        $this->client->request(
            'PATCH',
            '/api/decks/' . $id,
            [],
            [],
            $headers,
            json_encode($body),
        );

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function mockAlteredCore(array $cards): void
    {
        $json = json_encode($cards);
        $this->alteredCoreMock->setResponseFactory(
            new MockResponse($json, ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']])
        );
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Regression test: PATCH {"isPublic": true} must persist.
     * Draft deck → no altered-core call, no validation.
     */
    public function testPatchIsPublicSaved(): void
    {
        $sub  = 'user-' . __FUNCTION__;
        $deck = $this->post($sub, ['name' => 'My Deck', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);

        $updated = $this->patch($sub, $deck['id'], ['isPublic' => true]);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($updated['isPublic']);
        $this->assertNull($updated['formatErrors']);
    }

    /**
     * A non-draft deck with a format saves even when format rules are broken.
     * Errors go to formatErrors, not a 422.
     */
    public function testFormatErrorsStoredNotThrown(): void
    {
        $sub  = 'user-' . __FUNCTION__;
        $deck = $this->post($sub, [
            'name'     => 'Incomplete Deck',
            'isDraft'  => false,
            'format'   => 'standard',
            // no deckCards → deck will fail hero + size validation
        ]);

        // Expect 201, not 422
        $this->assertResponseStatusCodeSame(201);
        $this->assertNotEmpty($deck['formatErrors']);
        $this->assertContains('Deck must contain exactly 1 hero card.', $deck['formatErrors']);
    }

    /**
     * A non-draft deck with no format has null formatErrors.
     */
    public function testFormatErrorsNullWhenNoFormat(): void
    {
        $sub  = 'user-' . __FUNCTION__;
        $deck = $this->post($sub, ['name' => 'Free Deck', 'isDraft' => false]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($deck['formatErrors']);
    }

    /**
     * altered-core returning 500 must not throw — deck is saved with format errors.
     */
    public function testDeckSavedWhenAlteredCoreUnavailable(): void
    {
        $sub  = 'user-' . __FUNCTION__;
        $deck = $this->post($sub, ['name' => 'My Deck', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);

        // Simulate altered-core being down for the PATCH
        $this->alteredCoreMock->setResponseFactory(
            new MockResponse('Internal Server Error', ['http_code' => 500])
        );

        $updated = $this->patch($sub, $deck['id'], [
            'isDraft'   => false,
            'format'    => 'standard',
            'deckCards' => [
                ['cardReference' => 'ALT_CORE_B_MU_1_C', 'quantity' => 1],
            ],
        ]);

        // 200, not 500
        $this->assertResponseIsSuccessful();
        // format errors because cardsData was empty (no hero found, not enough cards)
        $this->assertNotEmpty($updated['formatErrors']);
    }

    /**
     * A non-draft standard deck with a valid hero and enough cards has null formatErrors.
     * altered-core is mocked to return proper card data.
     */
    public function testFormatErrorsNullOnValidDeck(): void
    {
        $sub = 'user-' . __FUNCTION__;

        // Build 39 common card references (13 distinct refs × qty 3)
        $deckCards = [];
        $mockCards = [];

        // Hero
        $heroRef     = 'ALT_CORE_B_AX_1_C';
        $deckCards[] = ['cardReference' => $heroRef, 'quantity' => 1];
        $mockCards[] = [
            'reference'  => $heroRef,
            'cardType'   => ['reference' => 'HERO_MAIN'],
            'faction'    => ['code' => 'AX'],
            'cardRarity' => ['reference' => 'CORAX_C'],
        ];

        // 13 distinct common cards × qty 3 = 39
        for ($i = 2; $i <= 14; $i++) {
            $ref         = sprintf('ALT_CORE_B_AX_%d_C', $i);
            $deckCards[] = ['cardReference' => $ref, 'quantity' => 3];
            $mockCards[] = [
                'reference'  => $ref,
                'cardType'   => ['reference' => 'PERMANENT'],
                'faction'    => ['code' => 'AX'],
                'cardRarity' => ['reference' => 'CORAX_C'],
            ];
        }

        $this->mockAlteredCore($mockCards);

        $deck = $this->post($sub, [
            'name'      => 'Valid Deck',
            'isDraft'   => false,
            'format'    => 'standard',
            'deckCards' => $deckCards,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($deck['formatErrors']);
        $this->assertNotNull($deck['stats']);
        $this->assertSame(39, $deck['stats']['totalCards']);
    }
}
