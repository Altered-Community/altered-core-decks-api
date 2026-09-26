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
        $body = $this->getMyRaw($sub, ['faction' => 'MU']);
        $this->assertResponseIsSuccessful();
        $this->assertSame('[]', trim($body));
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

    // ── Public decks: legal filter ─────────────────────────────────────────────

    /**
     * Creates a public, non-draft deck (unless $body overrides it) and forces its stored legality (and optionally its
     * hero and creation date) via DBAL. `legal` is computed server-side from the format
     * rules, so writing it directly is the only way to get both states without building
     * full format-valid decks.
     *
     * @param array<string, mixed> $body extra POST body fields (format, deckCards, ...)
     *
     * @return array<string, mixed>
     */
    private function postPublicDeck(string $sub, string $name, bool $legal, array $body = [], ?string $heroRef = null, ?string $createdAt = null): array
    {
        $deck = $this->post($sub, $body + ['name' => $name, 'isDraft' => false, 'isPublic' => true]);
        $this->assertResponseStatusCodeSame(201);

        $connection = static::getContainer()->get('doctrine')->getConnection();
        $connection->executeStatement(
            'UPDATE deck SET legal = :legal WHERE id = :id',
            ['legal' => $legal ? 'true' : 'false', 'id' => $deck['id']],
        );
        if (null !== $heroRef) {
            $connection->executeStatement(
                "UPDATE deck SET stats = jsonb_set(COALESCE(stats::jsonb, '{}'::jsonb), '{hero}', :hero::jsonb)::json WHERE id = :id",
                ['hero' => json_encode(['reference' => $heroRef, 'name' => 'Hero', 'imagePath' => '/img/hero.jpg']), 'id' => $deck['id']],
            );
        }
        if (null !== $createdAt) {
            $connection->executeStatement(
                'UPDATE deck SET created_at = :createdAt WHERE id = :id',
                ['createdAt' => $createdAt, 'id' => $deck['id']],
            );
        }

        return $deck;
    }

    /**
     * Three public decks sharing a unique name token: two legal, one illegal. Filtering on
     * `name` isolates them from decks left in the shared test database by other tests.
     *
     * @return array{token: string, legal: list<string>, illegal: list<string>}
     */
    private function seedLegalAndIllegalDecks(string $sub): array
    {
        $token = 'legaltok'.substr(md5($sub), 0, 10);
        $a = $this->postPublicDeck($sub, $token.' A', true, createdAt: '2026-01-01 10:00:00');
        $b = $this->postPublicDeck($sub, $token.' B', false, createdAt: '2026-01-02 10:00:00');
        $c = $this->postPublicDeck($sub, $token.' C', true, createdAt: '2026-01-03 10:00:00');

        return ['token' => $token, 'legal' => [$a['id'], $c['id']], 'illegal' => [$b['id']]];
    }

    public function testPublicDecksLegalFilter(): void
    {
        $seed = $this->seedLegalAndIllegalDecks('user-'.__FUNCTION__);

        foreach (['true', '1', 'TRUE', 'True'] as $value) {
            $data = $this->getPublic(['name' => $seed['token'], 'legal' => $value]);
            $this->assertResponseIsSuccessful();
            $this->assertSame(2, $data['totalItems'], "legal={$value}");
            $this->assertEqualsCanonicalizing($seed['legal'], array_column($data['member'], 'id'), "legal={$value}");
            $this->assertNotContains(false, array_column($data['member'], 'legal'));
        }

        foreach (['false', '0', 'FALSE'] as $value) {
            $data = $this->getPublic(['name' => $seed['token'], 'legal' => $value]);
            $this->assertResponseIsSuccessful();
            $this->assertSame(1, $data['totalItems'], "legal={$value}");
            $this->assertSame($seed['illegal'], array_column($data['member'], 'id'), "legal={$value}");
            $this->assertNotContains(true, array_column($data['member'], 'legal'));
        }

        // No `legal` param: legal and illegal decks alike, as before the filter existed.
        $data = $this->getPublic(['name' => $seed['token']]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(3, $data['totalItems']);
        $this->assertEqualsCanonicalizing([...$seed['legal'], ...$seed['illegal']], array_column($data['member'], 'id'));
    }

    public function testPublicDecksInvalidLegalValueIsIgnored(): void
    {
        $seed = $this->seedLegalAndIllegalDecks('user-'.__FUNCTION__);

        $this->client->request('GET', '/api/decks/public', ['name' => $seed['token']]);
        $baseline = (string) $this->client->getResponse()->getContent();

        // Unrecognised values fall back to "no filter": same response, byte for byte, as omitting the param.
        foreach (['', 'yes', 'no', 'on', 'maybe', '2', '-1', 'truee', ' true', ['true'], ['x' => '1']] as $value) {
            $this->client->request('GET', '/api/decks/public', ['name' => $seed['token'], 'legal' => $value]);
            $this->assertResponseIsSuccessful();
            $this->assertSame($baseline, (string) $this->client->getResponse()->getContent(), 'legal='.json_encode($value));
        }

        $this->assertSame(3, json_decode($baseline, true)['totalItems']);
    }

    public function testPublicDecksLegalFilterPaginationAndTotals(): void
    {
        $seed = $this->seedLegalAndIllegalDecks('user-'.__FUNCTION__);

        // Default order (createdAt DESC) is C (legal), B (illegal), A (legal). Without the filter
        // page 2 holds the illegal deck; with it, every page is full and the totals exclude it.
        $unfiltered = $this->getPublic(['name' => $seed['token'], 'itemsPerPage' => 1, 'page' => 2]);
        $this->assertSame($seed['illegal'], array_column($unfiltered['member'], 'id'));
        $this->assertSame(3, $unfiltered['totalItems']);
        $this->assertSame(3, $unfiltered['lastPage']);

        $page1 = $this->getPublic(['name' => $seed['token'], 'legal' => 'true', 'itemsPerPage' => 1, 'page' => 1]);
        $this->assertResponseIsSuccessful();
        $this->assertSame([$seed['legal'][1]], array_column($page1['member'], 'id'));
        $this->assertSame(2, $page1['totalItems']);
        $this->assertSame(1, $page1['currentPage']);
        $this->assertSame(2, $page1['lastPage']);
        $this->assertSame(2, $page1['nextPage']);
        $this->assertNull($page1['previousPage']);

        $page2 = $this->getPublic(['name' => $seed['token'], 'legal' => 'true', 'itemsPerPage' => 1, 'page' => 2]);
        $this->assertSame([$seed['legal'][0]], array_column($page2['member'], 'id'));
        $this->assertSame(2, $page2['lastPage']);
        $this->assertNull($page2['nextPage']);
        $this->assertSame(1, $page2['previousPage']);

        // Past the last page: empty member, totals unchanged.
        $page3 = $this->getPublic(['name' => $seed['token'], 'legal' => 'true', 'itemsPerPage' => 1, 'page' => 3]);
        $this->assertSame([], $page3['member']);
        $this->assertSame(2, $page3['totalItems']);

        $illegalOnly = $this->getPublic(['name' => $seed['token'], 'legal' => 'false', 'itemsPerPage' => 1]);
        $this->assertSame(1, $illegalOnly['totalItems']);
        $this->assertSame(1, $illegalOnly['lastPage']);
        $this->assertNull($illegalOnly['nextPage']);
    }

    public function testPublicDecksLegalFilterWithEveryOrder(): void
    {
        $seed = $this->seedLegalAndIllegalDecks('user-'.__FUNCTION__);

        foreach (['name', 'createdAt', 'updatedAt', 'upvoteCount', 'viewCount'] as $field) {
            foreach (['asc', 'desc'] as $dir) {
                $label = "order[{$field}]={$dir}";

                $legal = $this->getPublic(['name' => $seed['token'], 'legal' => 'true', 'order' => [$field => $dir]]);
                $this->assertResponseIsSuccessful();
                $this->assertSame(2, $legal['totalItems'], $label);
                $this->assertEqualsCanonicalizing($seed['legal'], array_column($legal['member'], 'id'), $label);

                $illegal = $this->getPublic(['name' => $seed['token'], 'legal' => 'false', 'order' => [$field => $dir]]);
                $this->assertSame(1, $illegal['totalItems'], $label);
                $this->assertSame($seed['illegal'], array_column($illegal['member'], 'id'), $label);
            }
        }

        // Order is still applied on the filtered set.
        $byName = $this->getPublic(['name' => $seed['token'], 'legal' => 'true', 'order' => ['name' => 'desc']]);
        $this->assertSame([$seed['legal'][1], $seed['legal'][0]], array_column($byName['member'], 'id'));
        $byName = $this->getPublic(['name' => $seed['token'], 'legal' => 'true', 'order' => ['name' => 'asc']]);
        $this->assertSame($seed['legal'], array_column($byName['member'], 'id'));
    }

    public function testPublicDecksLegalFilterCombinesWithFormatHeroAndFaction(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $token = 'legalcombo'.substr(md5($sub), 0, 10);

        $axLegal = $this->postPublicDeck($sub, $token.' AX legal', true, ['format' => 'standard'], 'ALT_CORE_B_AX_1_C');
        $axIllegal = $this->postPublicDeck($sub, $token.' AX illegal', false, ['format' => 'standard'], 'ALT_BISE_B_AX_1_C');
        $lyLegal = $this->postPublicDeck($sub, $token.' LY legal', true, ['format' => 'standard'], 'ALT_CORE_B_LY_1_C');
        $lyIllegalNoFormat = $this->postPublicDeck($sub, $token.' LY illegal', false, [], 'ALT_CORE_B_LY_1_C');

        $cases = [
            'format' => [['format' => 'standard'], [$axLegal, $lyLegal], [$axIllegal]],
            'faction' => [['faction' => 'AX'], [$axLegal], [$axIllegal]],
            'hero' => [['hero' => 'ALT_CORE_B_AX_1_C'], [$axLegal], [$axIllegal]],
            'faction+hero' => [['faction' => 'LY', 'hero' => 'ALT_CORE_B_LY_1_C'], [$lyLegal], [$lyIllegalNoFormat]],
            'format+faction' => [['format' => 'standard', 'faction' => 'LY'], [$lyLegal], []],
        ];

        foreach ($cases as $label => [$filters, $expectedLegal, $expectedIllegal]) {
            $legal = $this->getPublic(['name' => $token, 'legal' => 'true'] + $filters);
            $this->assertResponseIsSuccessful();
            $this->assertEqualsCanonicalizing(array_column($expectedLegal, 'id'), array_column($legal['member'], 'id'), "{$label} legal=true");
            $this->assertSame(count($expectedLegal), $legal['totalItems'], "{$label} legal=true");

            $illegal = $this->getPublic(['name' => $token, 'legal' => 'false'] + $filters);
            $this->assertEqualsCanonicalizing(array_column($expectedIllegal, 'id'), array_column($illegal['member'], 'id'), "{$label} legal=false");
            $this->assertSame(count($expectedIllegal), $illegal['totalItems'], "{$label} legal=false");

            $all = $this->getPublic(['name' => $token] + $filters);
            $this->assertSame(count($expectedLegal) + count($expectedIllegal), $all['totalItems'], "{$label} no legal");
        }
    }

    public function testPublicDecksLegalFilterCombinesWithCardFilters(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $token = 'legalcard'.substr(md5($sub), 0, 10);
        $cardRef = 'ALT_CORE_B_AX_4_C';

        $this->mockAlteredCore([[
            'reference' => $cardRef,
            'name' => 'Legalitycheckcard',
            'cardType' => ['reference' => 'PERMANENT'],
            'faction' => ['code' => 'AX'],
            'cardRarity' => ['reference' => 'CORAX_C'],
        ]]);

        $withCardLegal = $this->postPublicDeck($sub, $token.' with legal', true, ['deckCards' => [['cardReference' => $cardRef, 'quantity' => 1]]]);
        $withCardIllegal = $this->postPublicDeck($sub, $token.' with illegal', false, ['deckCards' => [['cardReference' => $cardRef, 'quantity' => 2]]]);
        $withoutCard = $this->postPublicDeck($sub, $token.' without legal', true);

        foreach (['cardName' => 'legalitycheck', 'cardReference' => $cardRef] as $param => $value) {
            $legal = $this->getPublic(['name' => $token, $param => $value, 'legal' => 'true']);
            $this->assertResponseIsSuccessful();
            $this->assertSame([$withCardLegal['id']], array_column($legal['member'], 'id'), "{$param} legal=true");
            $this->assertSame(1, $legal['totalItems'], "{$param} legal=true");

            $illegal = $this->getPublic(['name' => $token, $param => $value, 'legal' => 'false']);
            $this->assertSame([$withCardIllegal['id']], array_column($illegal['member'], 'id'), "{$param} legal=false");
            $this->assertSame(1, $illegal['totalItems'], "{$param} legal=false");

            $all = $this->getPublic(['name' => $token, $param => $value]);
            $this->assertSame(2, $all['totalItems'], "{$param} no legal");
            $this->assertNotContains($withoutCard['id'], array_column($all['member'], 'id'));
        }
    }

    public function testPublicDecksLegalFilterExcludesPrivateAndDraftDecks(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $token = 'legalvis'.substr(md5($sub), 0, 10);

        $public = $this->postPublicDeck($sub, $token.' public', true);
        $this->postPublicDeck($sub, $token.' private', true, ['isPublic' => false]);
        $this->postPublicDeck($sub, $token.' draft', true, ['isDraft' => true]);

        // A legal deck still needs to be public and non-draft to be listed.
        $data = $this->getPublic(['name' => $token, 'legal' => 'true']);
        $this->assertSame([$public['id']], array_column($data['member'], 'id'));
        $this->assertSame(1, $data['totalItems']);
    }
}
