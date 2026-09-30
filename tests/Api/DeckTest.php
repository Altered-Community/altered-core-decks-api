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
        $this->client = static::createClient();
        // Keep a single kernel/container across requests so mocks reconfigured between
        // successive requests (e.g. several POSTs each needing different card data) stick.
        $this->client->disableReboot();
        $this->alteredCoreMock = static::getContainer()->get('altered_core.mock_http_client');
        // Default: return empty card list (deck with no cards never triggers HTTP call,
        // but this prevents MockHttpClient from throwing if called unexpectedly)
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
            'email' => 'test@test.com',
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
        $headers = $this->authHeaders($sub);
        $headers['CONTENT_TYPE'] = 'application/merge-patch+json';

        $this->client->request(
            'PATCH',
            '/api/decks/'.$id,
            [],
            [],
            $headers,
            json_encode($body),
        );

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function delete(string $sub, string $id): void
    {
        $this->client->request(
            'DELETE',
            '/api/decks/'.$id,
            [],
            [],
            $this->authHeaders($sub),
        );
    }

    private function mockAlteredCore(array $cards): void
    {
        // AlteredCoreClient caches card data per reference in cache.app. With kernel reboot
        // disabled (see setUp), the in-process array cache survives across requests within a
        // test, so clear it here to guarantee a re-mock of the same reference takes effect.
        static::getContainer()->get('cache.app')->clear();

        $json = json_encode($cards);
        $this->alteredCoreMock->setResponseFactory(
            static fn (): MockResponse => new MockResponse($json, ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']])
        );
    }

    private function upvote(string $sub, string $id): array
    {
        $this->client->request(
            'POST',
            '/api/decks/'.$id.'/upvote',
            [],
            [],
            $this->authHeaders($sub),
        );

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function getPublic(array $params = [], ?string $sub = null): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $sub) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$this->makeToken($sub);
        }

        $this->client->request('GET', '/api/decks/public', $params, [], $headers);

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Regression test: PATCH {"isPublic": true} must persist.
     * Draft deck → no altered-core call, no validation.
     */
    public function testPatchIsPublicSaved(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'My Deck', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);

        $updated = $this->patch($sub, $deck['id'], ['isPublic' => true]);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($updated['isPublic']);
        $this->assertNull($updated['formatErrors']);
    }

    public function testDeleteOwnDeckReturns204(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'To Delete', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);

        $this->delete($sub, $deck['id']);
        $this->assertResponseStatusCodeSame(204);

        // Verify it's gone
        $this->client->request('GET', '/api/decks/'.$deck['id'], [], [], $this->authHeaders($sub));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteByAnotherUserReturns403(): void
    {
        $owner = 'owner-'.__FUNCTION__;
        $other = 'other-'.__FUNCTION__;

        $deck = $this->post($owner, ['name' => 'My Deck', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);

        $this->delete($other, $deck['id']);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeleteWithoutAuthReturns401(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'My Deck', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/decks/'.$deck['id']);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testDeleteNonExistentDeckReturns404(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $this->delete($sub, '00000000-0000-0000-0000-000000000000');
        $this->assertResponseStatusCodeSame(404);
    }

    // ── alteredId ─────────────────────────────────────────────────────────────

    public function testPostWithAlteredIdSavesIt(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'My Deck', 'isDraft' => true, 'alteredId' => 'altered-abc-123']);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('altered-abc-123', $deck['alteredId']);
    }

    public function testPostWithoutAlteredIdReturnsNull(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'My Deck', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($deck['alteredId']);
    }

    public function testDuplicateAlteredIdReturns422(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $this->post($sub, ['name' => 'First', 'isDraft' => true, 'alteredId' => 'same-altered-id']);
        $this->assertResponseStatusCodeSame(201);

        $this->post($sub, ['name' => 'Second', 'isDraft' => true, 'alteredId' => 'same-altered-id']);
        $this->assertResponseStatusCodeSame(422);

        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('alteredId', $body['violations'][0]['propertyPath']);
    }

    public function testDuplicateAlteredIdAcrossUserReturns422(): void
    {
        $this->post('user-a-'.__FUNCTION__, ['name' => 'Deck A', 'isDraft' => true, 'alteredId' => 'shared-altered-id']);
        $this->assertResponseStatusCodeSame(201);

        $this->post('user-b-'.__FUNCTION__, ['name' => 'Deck B', 'isDraft' => true, 'alteredId' => 'shared-altered-id']);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testNullAlteredIdAllowedOnMultipleDecks(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $this->post($sub, ['name' => 'Deck 1', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);
        $this->post($sub, ['name' => 'Deck 2', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testPatchAlteredIdUpdatesIt(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'My Deck', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);

        $updated = $this->patch($sub, $deck['id'], ['alteredId' => 'new-altered-id']);
        $this->assertResponseIsSuccessful();
        $this->assertSame('new-altered-id', $updated['alteredId']);
    }

    public function testPatchAlteredIdToExistingReturns422(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $this->post($sub, ['name' => 'Deck A', 'isDraft' => true, 'alteredId' => 'taken-id']);
        $this->assertResponseStatusCodeSame(201);

        $deckB = $this->post($sub, ['name' => 'Deck B', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);

        $this->patch($sub, $deckB['id'], ['alteredId' => 'taken-id']);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testChangingFormatAwayFromSealedReturns422(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'Sealed Deck', 'isDraft' => true, 'format' => 'sealed']);
        $this->assertResponseStatusCodeSame(201);

        $this->patch($sub, $deck['id'], ['format' => 'standard']);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchingSealedDeckWithoutChangingFormatSucceeds(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'Sealed Deck', 'isDraft' => true, 'format' => 'sealed']);
        $this->assertResponseStatusCodeSame(201);

        $updated = $this->patch($sub, $deck['id'], ['name' => 'Renamed Sealed Deck']);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Renamed Sealed Deck', $updated['name']);
        $this->assertSame('sealed', $updated['format']);
    }

    public function testChangingFormatToSealedReturns422(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'Standard Deck', 'isDraft' => true, 'format' => 'standard']);
        $this->assertResponseStatusCodeSame(201);

        $this->patch($sub, $deck['id'], ['format' => 'sealed']);
        $this->assertResponseStatusCodeSame(422);
    }

    /**
     * A non-draft deck with a format saves even when format rules are broken.
     * Errors go to formatErrors, not a 422.
     */
    public function testFormatErrorsStoredNotThrown(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, [
            'name' => 'Incomplete Deck',
            'isDraft' => false,
            'format' => 'standard',
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
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'Free Deck', 'isDraft' => false]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($deck['formatErrors']);
    }

    /**
     * altered-core returning 500 must not throw — deck is saved with format errors.
     */
    public function testDeckSavedWhenAlteredCoreUnavailable(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'My Deck', 'isDraft' => true]);
        $this->assertResponseStatusCodeSame(201);

        // Simulate altered-core being down for the PATCH
        $this->alteredCoreMock->setResponseFactory(
            new MockResponse('Internal Server Error', ['http_code' => 500])
        );

        $updated = $this->patch($sub, $deck['id'], [
            'isDraft' => false,
            'format' => 'standard',
            'deckCards' => [
                ['cardReference' => 'ALT_CORE_B_MU_1_C', 'quantity' => 1],
            ],
        ]);

        // 200, not 500
        $this->assertResponseIsSuccessful();
        // legality is not recomputed when altered-core is unavailable — previous state preserved
        $this->assertNull($updated['formatErrors']);
        $this->assertNull($updated['legalityDetail']);
    }

    /**
     * A non-draft standard deck with a valid hero and enough cards has null formatErrors.
     * altered-core is mocked to return proper card data.
     */
    public function testFormatErrorsNullOnValidDeck(): void
    {
        $sub = 'user-'.__FUNCTION__;

        // Build 39 common card references (13 distinct refs × qty 3)
        $deckCards = [];
        $mockCards = [];

        // Hero
        $heroRef = 'ALT_CORE_B_AX_1_C';
        $deckCards[] = ['cardReference' => $heroRef, 'quantity' => 1];
        $mockCards[] = [
            'reference' => $heroRef,
            'cardType' => ['reference' => 'HERO_MAIN'],
            'faction' => ['code' => 'AX'],
            'cardRarity' => ['reference' => 'CORAX_C'],
        ];

        // 13 distinct common cards × qty 3 = 39
        for ($i = 2; $i <= 14; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_C', $i);
            $deckCards[] = ['cardReference' => $ref, 'quantity' => 3];
            $mockCards[] = [
                'reference' => $ref,
                'cardType' => ['reference' => 'PERMANENT'],
                'faction' => ['code' => 'AX'],
                'cardRarity' => ['reference' => 'CORAX_C'],
            ];
        }

        $this->mockAlteredCore($mockCards);

        $deck = $this->post($sub, [
            'name' => 'Valid Deck',
            'isDraft' => false,
            'format' => 'standard',
            'deckCards' => $deckCards,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($deck['formatErrors']);
        $this->assertNotNull($deck['stats']);
        $this->assertSame(39, $deck['stats']['totalCards']);
    }

    // ── Upvote ────────────────────────────────────────────────────────────────

    public function testUpvotePublicDeckToggles(): void
    {
        $owner = 'owner-'.__FUNCTION__;
        $voter = 'voter-'.__FUNCTION__;

        $deck = $this->post($owner, ['name' => 'Popular Deck', 'isDraft' => false]);
        $this->assertResponseStatusCodeSame(201);
        $this->patch($owner, $deck['id'], ['isPublic' => true]);

        $result = $this->upvote($voter, $deck['id']);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($result['hasUpvoted']);
        $this->assertSame(1, $result['upvoteCount']);

        $result = $this->upvote($voter, $deck['id']);
        $this->assertResponseIsSuccessful();
        $this->assertFalse($result['hasUpvoted']);
        $this->assertSame(0, $result['upvoteCount']);
    }

    public function testUpvoteWithoutAuthReturns401(): void
    {
        $owner = 'owner-'.__FUNCTION__;
        $deck = $this->post($owner, ['name' => 'Deck', 'isDraft' => false]);
        $this->assertResponseStatusCodeSame(201);
        $this->patch($owner, $deck['id'], ['isPublic' => true]);

        $this->client->request('POST', '/api/decks/'.$deck['id'].'/upvote');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testUpvotePrivateDeckReturns404(): void
    {
        $owner = 'owner-'.__FUNCTION__;
        $voter = 'voter-'.__FUNCTION__;

        $deck = $this->post($owner, ['name' => 'Private Deck', 'isDraft' => false]);
        $this->assertResponseStatusCodeSame(201);

        $this->upvote($voter, $deck['id']);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpvoteNonExistentDeckReturns404(): void
    {
        $this->upvote('user-'.__FUNCTION__, '00000000-0000-0000-0000-000000000000');
        $this->assertResponseStatusCodeSame(404);
    }

    // ── Public deck list ──────────────────────────────────────────────────────

    public function testPublicDeckListHasUpvotedFalseForAnonymous(): void
    {
        $owner = 'owner-'.__FUNCTION__;
        $deck = $this->post($owner, ['name' => 'Public Deck '.__FUNCTION__, 'isDraft' => false]);
        $this->assertResponseStatusCodeSame(201);
        $this->patch($owner, $deck['id'], ['isPublic' => true]);

        $data = $this->getPublic(['itemsPerPage' => 1000]);
        $this->assertResponseIsSuccessful();

        $found = array_values(array_filter($data['member'], fn ($d) => $d['id'] === $deck['id']));
        $this->assertNotEmpty($found, 'Deck should appear in public listing');
        $this->assertFalse($found[0]['hasUpvoted']);
    }

    public function testPublicDeckListHasUpvotedTrueAfterUpvote(): void
    {
        $owner = 'owner-'.__FUNCTION__;
        $voter = 'voter-'.__FUNCTION__;

        $deck = $this->post($owner, ['name' => 'Popular Deck '.__FUNCTION__, 'isDraft' => false]);
        $this->assertResponseStatusCodeSame(201);
        $this->patch($owner, $deck['id'], ['isPublic' => true]);

        $this->upvote($voter, $deck['id']);

        $data = $this->getPublic(['itemsPerPage' => 1000], $voter);
        $this->assertResponseIsSuccessful();

        $found = array_values(array_filter($data['member'], fn ($d) => $d['id'] === $deck['id']));
        $this->assertNotEmpty($found, 'Deck should appear in public listing');
        $this->assertTrue($found[0]['hasUpvoted']);
    }

    public function testPublicDeckListHasUpvotedFalseForOtherUser(): void
    {
        $owner = 'owner-'.__FUNCTION__;
        $voter = 'voter-'.__FUNCTION__;
        $other = 'other-'.__FUNCTION__;

        $deck = $this->post($owner, ['name' => 'Deck '.__FUNCTION__, 'isDraft' => false]);
        $this->assertResponseStatusCodeSame(201);
        $this->patch($owner, $deck['id'], ['isPublic' => true]);
        $this->upvote($voter, $deck['id']);

        $data = $this->getPublic(['itemsPerPage' => 1000], $other);
        $found = array_values(array_filter($data['member'], fn ($d) => $d['id'] === $deck['id']));
        $this->assertNotEmpty($found);
        $this->assertFalse($found[0]['hasUpvoted']);
    }

    public function testPublicDecksOrderParam(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->post($sub, ['name' => 'Deck '.__FUNCTION__, 'isDraft' => false]);
        $this->assertResponseStatusCodeSame(201);
        $this->patch($sub, $deck['id'], ['isPublic' => true]);

        foreach (['name', 'createdAt', 'updatedAt', 'upvoteCount', 'viewCount'] as $field) {
            foreach (['asc', 'desc'] as $dir) {
                $data = $this->getPublic(['order' => [$field => $dir]]);
                $this->assertResponseIsSuccessful();
                $this->assertArrayHasKey('member', $data);
            }
        }
    }

    public function testPublicDecksMalformedOrderParamFallsBackToDefault(): void
    {
        foreach (['asc', ['name' => ['asc']], ['unknown' => 'asc']] as $order) {
            $data = $this->getPublic(['order' => $order]);
            $this->assertResponseIsSuccessful();
            $this->assertArrayHasKey('member', $data);
        }
    }

    // ── Card name search ──────────────────────────────────────────────────────

    public function testCardNamePopulatedFromAlteredCore(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $cardRef = 'ALT_CORE_B_AX_1_C';

        $this->mockAlteredCore([[
            'reference' => $cardRef,
            'name' => 'Morgane',
            'cardType' => ['reference' => 'PERMANENT'],
            'faction' => ['code' => 'AX'],
            'cardRarity' => ['reference' => 'CORAX_C'],
        ]]);

        $this->post($sub, [
            'name' => 'Deck With Morgane '.__FUNCTION__,
            'isDraft' => false,
            'isPublic' => true,
            'deckCards' => [['cardReference' => $cardRef, 'quantity' => 1]],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Verify the name was persisted: the cardName filter only works when deck_card.name is stored
        $result = $this->getPublic(['cardName' => 'Morgane']);
        $this->assertGreaterThanOrEqual(1, $result['totalItems']);
    }

    public function testPublicDecksFilterByCardName(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $cardRef = 'ALT_CORE_B_AX_2_C';

        $this->mockAlteredCore([[
            'reference' => $cardRef,
            'name' => 'Morgane',
            'cardType' => ['reference' => 'PERMANENT'],
            'faction' => ['code' => 'AX'],
            'cardRarity' => ['reference' => 'CORAX_C'],
        ]]);

        $deckWith = $this->post($sub, [
            'name' => 'Deck With Morgane '.__FUNCTION__,
            'isDraft' => false,
            'deckCards' => [['cardReference' => $cardRef, 'quantity' => 1]],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->patch($sub, $deckWith['id'], ['isPublic' => true]);

        // reset mock — second deck has no cards, altered-core won't be called
        $this->alteredCoreMock->setResponseFactory(
            static fn (): MockResponse => new MockResponse('[]', ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']])
        );

        $deckWithout = $this->post($sub, ['name' => 'Deck Without Morgane '.__FUNCTION__, 'isDraft' => false]);
        $this->assertResponseStatusCodeSame(201);
        $this->patch($sub, $deckWithout['id'], ['isPublic' => true]);

        $data = $this->getPublic(['cardName' => 'Morgane', 'itemsPerPage' => 1000]);
        $this->assertResponseIsSuccessful();

        $ids = array_column($data['member'], 'id');
        $this->assertContains($deckWith['id'], $ids);
        $this->assertNotContains($deckWithout['id'], $ids);
    }

    public function testPublicDecksFilterByCardNameCaseInsensitive(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $cardRef = 'ALT_CORE_B_AX_3_C';

        $this->mockAlteredCore([[
            'reference' => $cardRef,
            'name' => 'Morgane',
            'cardType' => ['reference' => 'PERMANENT'],
            'faction' => ['code' => 'AX'],
            'cardRarity' => ['reference' => 'CORAX_C'],
        ]]);

        $deck = $this->post($sub, [
            'name' => 'Deck '.__FUNCTION__,
            'isDraft' => false,
            'deckCards' => [['cardReference' => $cardRef, 'quantity' => 1]],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->patch($sub, $deck['id'], ['isPublic' => true]);

        // lowercase search → still matches
        $data = $this->getPublic(['cardName' => 'morgane', 'itemsPerPage' => 1000]);
        $this->assertResponseIsSuccessful();
        $this->assertContains($deck['id'], array_column($data['member'], 'id'));

        // partial search → still matches
        $data = $this->getPublic(['cardName' => 'morg', 'itemsPerPage' => 1000]);
        $this->assertResponseIsSuccessful();
        $this->assertContains($deck['id'], array_column($data['member'], 'id'));
    }

    // ── My decks: faction / hero filters ───────────────────────────────────────

    /**
     * Creates a non-draft deck whose only card is a hero, so stats.hero.reference
     * is populated (the value the faction/hero filters match on).
     *
     * @return array<string, mixed>
     */
    private function postHeroDeck(string $sub, string $name, string $heroRef): array
    {
        $this->mockAlteredCore([[
            'reference' => $heroRef,
            'name' => 'Hero '.$heroRef,
            'cardType' => ['reference' => 'HERO_MAIN'],
            'faction' => ['code' => explode('_', $heroRef)[3] ?? 'AX'],
            'cardRarity' => ['reference' => 'CORAX_C'],
        ]]);

        $deck = $this->post($sub, [
            'name' => $name,
            'isDraft' => false,
            'deckCards' => [['cardReference' => $heroRef, 'quantity' => 1]],
        ]);
        $this->assertResponseStatusCodeSame(201);

        return $deck;
    }

    private function getMyRaw(string $sub, array $params = []): string
    {
        $this->client->request('GET', '/api/decks', $params, [], $this->authHeaders($sub));

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function getMy(string $sub, array $params = []): array
    {
        return json_decode($this->getMyRaw($sub, $params), true) ?? [];
    }

    public function testMyDecksFilterByFaction(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $ax = $this->postHeroDeck($sub, 'AX Deck', 'ALT_CORE_B_AX_1_C');
        $ly = $this->postHeroDeck($sub, 'LY Deck', 'ALT_CORE_B_LY_1_C');

        $body = $this->getMyRaw($sub, ['faction' => 'LY']);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($ly['id'], $body, 'LY deck should be returned');
        $this->assertStringNotContainsString($ax['id'], $body, 'AX deck should be filtered out');

        // No filter → both decks returned.
        $all = $this->getMyRaw($sub);
        $this->assertStringContainsString($ax['id'], $all);
        $this->assertStringContainsString($ly['id'], $all);
    }

    public function testMyDecksFilterByHeroNormalisesAcrossSets(): void
    {
        $sub = 'user-'.__FUNCTION__;
        // Same hero identity (LY_1) across two different sets, plus a different hero (LY_2).
        $ly1core = $this->postHeroDeck($sub, 'LY1 CORE', 'ALT_CORE_B_LY_1_C');
        $ly1bise = $this->postHeroDeck($sub, 'LY1 BISE', 'ALT_BISE_B_LY_1_C');
        $ly2 = $this->postHeroDeck($sub, 'LY2', 'ALT_CORE_B_LY_2_C');

        $body = $this->getMyRaw($sub, ['hero' => 'ALT_CORE_B_LY_1_C']);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($ly1core['id'], $body);
        $this->assertStringContainsString($ly1bise['id'], $body, 'Same hero across sets should match');
        $this->assertStringNotContainsString($ly2['id'], $body, 'A different hero should be filtered out');
    }

    public function testMyDecksFilterByFactionAndHeroCombined(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $lyMatch = $this->postHeroDeck($sub, 'LY match', 'ALT_CORE_B_LY_1_C');
        $lyOtherHero = $this->postHeroDeck($sub, 'LY other hero', 'ALT_CORE_B_LY_2_C');
        $axSameNumber = $this->postHeroDeck($sub, 'AX same number', 'ALT_CORE_B_AX_1_C');

        // Both params combine with AND: only the LY deck whose hero is LY_1 matches.
        $body = $this->getMyRaw($sub, ['faction' => 'LY', 'hero' => 'ALT_CORE_B_LY_1_C']);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($lyMatch['id'], $body);
        $this->assertStringNotContainsString($lyOtherHero['id'], $body, 'Wrong hero within faction is excluded');
        $this->assertStringNotContainsString($axSameNumber['id'], $body, 'Wrong faction with same hero number is excluded');
    }

    public function testMyDecksFilterByFactionNoMatchReturnsEmpty(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $this->postHeroDeck($sub, 'AX Deck', 'ALT_CORE_B_AX_1_C');

        // No deck belongs to Muna → empty result, not an error.
        $data = $this->getMy($sub, ['faction' => 'MU']);
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $data['member']);
        $this->assertSame(0, $data['totalItems']);
    }

    public function testMyDecksFactionFilterIsCaseSensitive(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $ly = $this->postHeroDeck($sub, 'LY Deck', 'ALT_CORE_B_LY_1_C');

        // Faction codes are compared with '=' (uppercase, as the client always sends them).
        $upper = $this->getMyRaw($sub, ['faction' => 'LY']);
        $this->assertStringContainsString($ly['id'], $upper);

        $lower = $this->getMyRaw($sub, ['faction' => 'ly']);
        $this->assertStringNotContainsString($ly['id'], $lower, 'Lowercase faction code must not match');
    }

    public function testMyDecksFilterExcludesOtherUsersDecks(): void
    {
        $owner = 'owner-'.__FUNCTION__;
        $other = 'other-'.__FUNCTION__;
        $mine = $this->postHeroDeck($owner, 'My LY', 'ALT_CORE_B_LY_1_C');
        $theirs = $this->postHeroDeck($other, 'Their LY', 'ALT_CORE_B_LY_1_C');

        // Same faction/hero, different owner: the filter must never leak another user's deck.
        $body = $this->getMyRaw($owner, ['faction' => 'LY']);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($mine['id'], $body);
        $this->assertStringNotContainsString($theirs['id'], $body, "Another user's deck must not appear");
    }

    // ── My decks: pagination / filters / ordering ─────────────────────────────

    /**
     * Creates $count draft decks named "$prefix 1" … "$prefix N" (oldest first).
     *
     * @return list<string> deck ids in creation order
     */
    private function postDrafts(string $sub, int $count, string $prefix = 'Deck'): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; ++$i) {
            $deck = $this->post($sub, ['name' => $prefix.' '.$i, 'isDraft' => true]);
            $this->assertResponseStatusCodeSame(201);
            $ids[] = $deck['id'];
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function myIds(string $sub, array $params = []): array
    {
        $data = $this->getMy($sub, $params);
        $this->assertResponseIsSuccessful();

        return array_column($data['member'], 'id');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function myNames(string $sub, array $params = []): array
    {
        $data = $this->getMy($sub, $params);
        $this->assertResponseIsSuccessful();

        return array_column($data['member'], 'name');
    }

    /**
     * Pins a deck's timestamps. The columns keep whole seconds only, so decks created by
     * one test usually share a timestamp; ordering tests set distinct values instead.
     */
    private function setTimestamps(string $id, string $createdAt, ?string $updatedAt = null): void
    {
        static::getContainer()->get('doctrine')->getConnection()->executeStatement(
            'UPDATE deck SET created_at = :c, updated_at = :u WHERE id = :id',
            ['c' => $createdAt, 'u' => $updatedAt, 'id' => $id],
        );
    }

    public function testMyDecksDefaultsToTwentyPerPage(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $this->postDrafts($sub, 23);

        $data = $this->getMy($sub);
        $this->assertResponseIsSuccessful();
        $this->assertCount(20, $data['member']);
        $this->assertSame(23, $data['totalItems']);
        $this->assertSame(20, $data['itemsPerPage']);
        $this->assertSame(1, $data['currentPage']);
        $this->assertSame(2, $data['lastPage']);
        $this->assertSame(2, $data['nextPage']);
        $this->assertNull($data['previousPage']);
        $this->assertSame('/api/decks?page=2', $data['view']['next']);
        $this->assertSame('/api/decks?page=2', $data['view']['last']);
        $this->assertNull($data['view']['previous']);
    }

    public function testMyDecksPagesAreDisjointAndCoverEveryDeck(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $all = $this->postDrafts($sub, 5);

        $page1 = $this->getMy($sub, ['page' => 1, 'itemsPerPage' => 2]);
        $page2 = $this->getMy($sub, ['page' => 2, 'itemsPerPage' => 2]);
        $page3 = $this->getMy($sub, ['page' => 3, 'itemsPerPage' => 2]);

        foreach ([$page1, $page2, $page3] as $page) {
            $this->assertSame(5, $page['totalItems']);
            $this->assertSame(3, $page['lastPage']);
        }

        $ids1 = array_column($page1['member'], 'id');
        $ids2 = array_column($page2['member'], 'id');
        $ids3 = array_column($page3['member'], 'id');
        $this->assertCount(2, $ids1);
        $this->assertCount(2, $ids2);
        $this->assertCount(1, $ids3);
        $this->assertSame([], array_intersect($ids1, $ids2), 'Page 2 must not repeat page 1');

        $seen = array_merge($ids1, $ids2, $ids3);
        sort($seen);
        sort($all);
        $this->assertSame($all, $seen);

        $this->assertSame(1, $page2['previousPage']);
        $this->assertSame(3, $page2['nextPage']);
        $this->assertNull($page3['nextPage']);
        $this->assertSame('/api/decks?page=3&itemsPerPage=2', $page2['view']['next']);
    }

    public function testMyDecksPageBeyondLastIsEmptyWithTotal(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $this->postDrafts($sub, 3);

        $data = $this->getMy($sub, ['page' => 5, 'itemsPerPage' => 2]);
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $data['member']);
        $this->assertSame(3, $data['totalItems']);
        $this->assertSame(2, $data['lastPage']);
    }

    public function testMyDecksItemsPerPageIsCappedAtOneThousand(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $this->postDrafts($sub, 1);

        $data = $this->getMy($sub, ['itemsPerPage' => 5000]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(1000, $data['itemsPerPage']);
        $this->assertCount(1, $data['member']);
    }

    public function testMyDecksInvalidPageReturns400(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $this->getMy($sub, ['page' => 0]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testMyDecksPaginationRespectsFilters(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $this->postDrafts($sub, 3, 'Alpha');
        $this->postDrafts($sub, 2, 'Beta');

        $data = $this->getMy($sub, ['name' => 'alpha', 'itemsPerPage' => 2]);
        $this->assertSame(3, $data['totalItems']);
        $this->assertSame(2, $data['lastPage']);
        $this->assertSame('/api/decks?name=alpha&itemsPerPage=2&page=2', $data['view']['next']);
    }

    public function testMyDecksFilterByFormat(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $standard = $this->post($sub, ['name' => 'Standard', 'isDraft' => true, 'format' => 'standard']);
        $nuc = $this->post($sub, ['name' => 'NUC', 'isDraft' => true, 'format' => 'nuc']);
        $none = $this->post($sub, ['name' => 'No format', 'isDraft' => true]);

        $ids = $this->myIds($sub, ['format' => 'nuc']);
        $this->assertSame([$nuc['id']], $ids);

        $ids = $this->myIds($sub, ['format' => 'standard']);
        $this->assertSame([$standard['id']], $ids);
        $this->assertNotContains($none['id'], $ids);
    }

    public function testMyDecksFilterByIsPublic(): void
    {
        $sub = 'user-'.__FUNCTION__;
        [$public, $private] = $this->postDrafts($sub, 2);
        $this->patch($sub, $public, ['isPublic' => true]);
        $this->assertResponseIsSuccessful();

        $this->assertSame([$public], $this->myIds($sub, ['isPublic' => 'true']));
        $this->assertSame([$public], $this->myIds($sub, ['isPublic' => '1']));
        $this->assertSame([$private], $this->myIds($sub, ['isPublic' => 'false']));
        $this->assertSame([$private], $this->myIds($sub, ['isPublic' => '0']));
        // Anything else is not a boolean: no filter.
        $this->assertCount(2, $this->myIds($sub, ['isPublic' => 'maybe']));
    }

    public function testMyDecksFilterByIsDraft(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $draft = $this->post($sub, ['name' => 'Draft', 'isDraft' => true]);
        $final = $this->post($sub, ['name' => 'Final', 'isDraft' => false]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertSame([$draft['id']], $this->myIds($sub, ['isDraft' => 'true']));
        $this->assertSame([$final['id']], $this->myIds($sub, ['isDraft' => 'false']));
    }

    public function testMyDecksFilterByNameIsCaseInsensitivePartial(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $dragon = $this->post($sub, ['name' => 'Red Dragon Aggro', 'isDraft' => true]);
        $this->post($sub, ['name' => 'Blue Control', 'isDraft' => true]);

        $this->assertSame([$dragon['id']], $this->myIds($sub, ['name' => 'dragon']));
        $this->assertSame([$dragon['id']], $this->myIds($sub, ['name' => 'RED DRA']));
        $this->assertSame([], $this->myIds($sub, ['name' => 'green']));
    }

    public function testMyDecksNameFilterTreatsWildcardsLiterally(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $percent = $this->post($sub, ['name' => '100% Aggro', 'isDraft' => true]);
        $this->post($sub, ['name' => '1000 Aggro', 'isDraft' => true]);
        $underscore = $this->post($sub, ['name' => 'a_b', 'isDraft' => true]);
        $this->post($sub, ['name' => 'axb', 'isDraft' => true]);

        $this->assertSame([$percent['id']], $this->myIds($sub, ['name' => '100%']));
        $this->assertSame([$underscore['id']], $this->myIds($sub, ['name' => 'a_b']));
        // A quote is just a character: no SQL error, no match.
        $this->assertSame([], $this->myIds($sub, ['name' => "x' OR '1'='1"]));
    }

    public function testMyDecksFiltersCombine(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $match = $this->post($sub, ['name' => 'Target', 'isDraft' => true, 'format' => 'nuc']);
        $this->post($sub, ['name' => 'Target', 'isDraft' => true, 'format' => 'standard']);
        $this->post($sub, ['name' => 'Other', 'isDraft' => true, 'format' => 'nuc']);

        $data = $this->getMy($sub, ['name' => 'targ', 'format' => 'nuc', 'isDraft' => 'true']);
        $this->assertSame([$match['id']], array_column($data['member'], 'id'));
        $this->assertSame(1, $data['totalItems']);
    }

    public function testMyDecksDefaultOrderIsMostRecentlyUpdatedFirst(): void
    {
        $sub = 'user-'.__FUNCTION__;
        [$old, $edited, $recent] = $this->postDrafts($sub, 3);
        $this->setTimestamps($old, '2026-01-01 10:00:00');
        $this->setTimestamps($edited, '2026-01-02 10:00:00', '2026-01-05 10:00:00');
        // Never edited: updatedAt falls back to createdAt.
        $this->setTimestamps($recent, '2026-01-04 10:00:00');

        $this->assertSame([$edited, $recent, $old], $this->myIds($sub));
    }

    public function testMyDecksSameSecondKeepsNewestFirst(): void
    {
        $sub = 'user-'.__FUNCTION__;
        [$first, $second, $third] = $this->postDrafts($sub, 3);
        foreach ([$first, $second, $third] as $id) {
            $this->setTimestamps($id, '2026-01-01 10:00:00');
        }

        $this->assertSame([$third, $second, $first], $this->myIds($sub));
        $this->assertSame([$first, $second, $third], $this->myIds($sub, ['order' => ['createdAt' => 'asc']]));
    }

    public function testMyDecksOrderByName(): void
    {
        $sub = 'user-'.__FUNCTION__;
        foreach (['charlie', 'Alpha', 'bravo'] as $name) {
            $this->post($sub, ['name' => $name, 'isDraft' => true]);
        }

        $this->assertSame(['Alpha', 'bravo', 'charlie'], $this->myNames($sub, ['order' => ['name' => 'asc']]));
        $this->assertSame(['charlie', 'bravo', 'Alpha'], $this->myNames($sub, ['order' => ['name' => 'desc']]));
    }

    public function testMyDecksOrderByCreatedAt(): void
    {
        $sub = 'user-'.__FUNCTION__;
        [$a, $b, $c] = $this->postDrafts($sub, 3);
        $this->setTimestamps($a, '2026-01-02 10:00:00', '2026-01-09 10:00:00');
        $this->setTimestamps($b, '2026-01-01 10:00:00');
        $this->setTimestamps($c, '2026-01-03 10:00:00');

        $this->assertSame([$b, $a, $c], $this->myIds($sub, ['order' => ['createdAt' => 'asc']]));
        $this->assertSame([$c, $a, $b], $this->myIds($sub, ['order' => ['createdAt' => 'desc']]));
    }

    public function testMyDecksOrderByUpdatedAtAsc(): void
    {
        $sub = 'user-'.__FUNCTION__;
        [$a, $b] = $this->postDrafts($sub, 2);
        $this->setTimestamps($a, '2026-01-01 10:00:00', '2026-01-09 10:00:00');
        $this->setTimestamps($b, '2026-01-02 10:00:00');

        $this->assertSame([$b, $a], $this->myIds($sub, ['order' => ['updatedAt' => 'asc']]));
        $this->assertSame([$a, $b], $this->myIds($sub, ['order' => ['updatedAt' => 'desc']]));
    }

    public function testMyDecksOrderByUpvoteCount(): void
    {
        $owner = 'owner-'.__FUNCTION__;
        [$popular, $quiet] = $this->postDrafts($owner, 2);
        $this->patch($owner, $popular, ['isPublic' => true, 'isDraft' => false]);
        $this->assertResponseIsSuccessful();
        $this->upvote('fan-'.__FUNCTION__, $popular);
        $this->assertResponseIsSuccessful();

        $this->assertSame([$popular, $quiet], $this->myIds($owner, ['order' => ['upvoteCount' => 'desc']]));
        $this->assertSame([$quiet, $popular], $this->myIds($owner, ['order' => ['upvoteCount' => 'asc']]));
    }

    public function testMyDecksOrderPaginatesWithoutOverlap(): void
    {
        $sub = 'user-'.__FUNCTION__;
        // Same name everywhere: the sort value ties, the id tie-breaker keeps pages stable.
        $all = $this->postDrafts($sub, 4, 'Same');
        foreach ($all as $id) {
            $this->patch($sub, $id, ['name' => 'Same']);
        }

        $params = ['order' => ['name' => 'asc'], 'itemsPerPage' => 2];
        $ids1 = $this->myIds($sub, $params + ['page' => 1]);
        $ids2 = $this->myIds($sub, $params + ['page' => 2]);

        $seen = array_merge($ids1, $ids2);
        sort($seen);
        sort($all);
        $this->assertSame($all, $seen);
    }

    public function testMyDecksUnknownOrMalformedOrderFallsBackToDefault(): void
    {
        $sub = 'user-'.__FUNCTION__;
        [$older, $newer] = $this->postDrafts($sub, 2);
        $this->setTimestamps($older, '2026-01-01 10:00:00');
        $this->setTimestamps($newer, '2026-01-02 10:00:00');
        $default = [$newer, $older];

        $this->assertSame($default, $this->myIds($sub, ['order' => ['user_id; DROP TABLE deck' => 'asc']]));
        $this->assertSame($default, $this->myIds($sub, ['order' => 'asc']));
        // An unknown direction is read as desc.
        $this->assertSame($default, $this->myIds($sub, ['order' => ['createdAt' => 'sideways']]));
    }
}
