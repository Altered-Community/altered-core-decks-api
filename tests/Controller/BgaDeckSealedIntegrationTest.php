<?php

namespace App\Tests\Controller;

use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * End-to-end reproduction of the 422 reported on GET /api/bga/decks/{id} for a real
 * sealed decklist (EOLE prerelease). Exercises the full stack — POST /api/decks to
 * create the deck through DeckStateProcessor, then GET /api/bga/decks/{id} through
 * BgaDeckController::item(), which is the ONLY place SealedFormatValidator can throw
 * a 422 (DeckStateProcessor stores format errors on the entity instead of throwing —
 * see DeckTest::testFormatErrorsStoredNotThrown).
 *
 * Card data mocked on altered_core.mock_http_client is copied verbatim from
 * https://cards.alteredcore.org/api/cards/batch for these 28 references — several
 * "R2" rares and one Unique are out-of-faction (OOF): their reference keeps the
 * faction of the card they were printed from, but their real faction.code is
 * different (e.g. ALT_EOLE_B_YZ_106_R2 is faction LY, not YZ). Once real faction
 * data is used, the deck only spans 2 factions (AX, LY) — well under the sealed cap
 * of 3 — ruling that out as the 422's cause.
 */
class BgaDeckSealedIntegrationTest extends WebTestCase
{
    private KernelBrowser $client;
    private MockHttpClient $alteredCoreMock;
    private MockHttpClient $alteredDraftMock;

    /** [reference, quantity, cardType, real faction.code, rarity] */
    private const ROWS = [
        ['ALT_EOLE_B_AX_65_C', 1, 'HERO', 'AX', 'COMMON'],
        ['ALT_EOLE_B_AX_106_R2', 1, 'CHARACTER', 'LY', 'RARE'],
        ['ALT_EOLE_B_YZ_106_R2', 1, 'CHARACTER', 'LY', 'RARE'],
        ['ALT_EOLE_B_AX_106_C', 1, 'CHARACTER', 'AX', 'COMMON'],
        ['ALT_EOLE_B_LY_119_C', 1, 'CHARACTER', 'LY', 'COMMON'],
        ['ALT_EOLE_B_MU_107_R2', 1, 'CHARACTER', 'LY', 'RARE'],
        ['ALT_EOLE_B_LY_107_R1', 1, 'CHARACTER', 'LY', 'RARE'],
        ['ALT_EOLE_B_LY_107_C', 2, 'CHARACTER', 'LY', 'COMMON'],
        ['ALT_EOLE_B_LY_113_C', 1, 'CHARACTER', 'LY', 'COMMON'],
        ['ALT_EOLE_B_LY_108_C', 1, 'CHARACTER', 'LY', 'COMMON'],
        ['ALT_EOLE_B_BR_112_R2', 1, 'CHARACTER', 'LY', 'RARE'],
        ['ALT_EOLE_B_AX_110_C', 1, 'CHARACTER', 'AX', 'COMMON'],
        ['ALT_EOLE_B_AX_107_C', 1, 'CHARACTER', 'AX', 'COMMON'],
        ['ALT_EOLE_B_AX_122_C', 1, 'CHARACTER', 'AX', 'COMMON'],
        ['ALT_EOLE_B_AX_112_C', 1, 'CHARACTER', 'AX', 'COMMON'],
        ['ALT_EOLE_B_AX_114_C', 1, 'CHARACTER', 'AX', 'COMMON'],
        ['ALT_EOLE_B_MU_112_U_2439', 1, 'CHARACTER', 'AX', 'UNIQUE'],
        ['ALT_EOLE_B_MU_120_R2', 1, 'CHARACTER', 'LY', 'RARE'],
        ['ALT_EOLE_B_LY_106_U_261', 1, 'CHARACTER', 'LY', 'UNIQUE'],
        ['ALT_EOLE_B_AX_115_C', 1, 'CHARACTER', 'AX', 'COMMON'],
        ['ALT_EOLE_B_AX_116_C', 1, 'CHARACTER', 'AX', 'COMMON'],
        ['ALT_EOLE_B_AX_115_R1', 1, 'CHARACTER', 'AX', 'RARE'],
        ['ALT_EOLE_B_LY_114_R1', 1, 'CHARACTER', 'LY', 'RARE'],
        ['ALT_EOLE_B_LY_114_C', 1, 'CHARACTER', 'LY', 'COMMON'],
        ['ALT_EOLE_B_LY_117_E', 1, 'CHARACTER', 'LY', 'EXALTED'],
        ['ALT_EOLE_B_AX_119_C', 2, 'SPELL', 'AX', 'COMMON'],
        ['ALT_EOLE_B_YZ_121_R2', 1, 'LANDMARK_PERMANENT', 'AX', 'RARE'],
        ['ALT_EOLE_B_AX_121_C', 2, 'LANDMARK_PERMANENT', 'AX', 'COMMON'],
    ];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->alteredCoreMock = static::getContainer()->get('altered_core.mock_http_client');
        $this->alteredDraftMock = static::getContainer()->get('altered_draft.mock_http_client');
    }

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

    private function mockCardBatch(): void
    {
        static::getContainer()->get('cache.app')->clear();

        $cards = array_map(
            static fn (array $row) => [
                'reference' => $row[0],
                'cardType' => ['reference' => $row[2]],
                'cardSubTypes' => [],
                'faction' => ['code' => $row[3]],
                'rarity' => ['reference' => $row[4]],
                'name' => $row[0],
                'isBanned' => false,
                'isSuspended' => false,
                'artists' => [],
                'mainCost' => 1,
                'recallCost' => 1,
                'forestPower' => 1,
                'mountainPower' => 1,
                'oceanPower' => 1,
            ],
            self::ROWS,
        );
        $json = json_encode($cards);

        $this->alteredCoreMock->setResponseFactory(
            static fn (): MockResponse => new MockResponse($json, ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']])
        );
    }

    private function createSealedDeck(string $sub): array
    {
        $deckCards = array_map(
            static fn (array $row) => ['cardReference' => $row[0], 'quantity' => $row[1]],
            self::ROWS,
        );

        $this->client->request(
            'POST',
            '/api/decks',
            [],
            [],
            $this->authHeaders($sub),
            json_encode([
                'name' => 'User Sealed Deck',
                'isDraft' => false,
                'format' => 'sealed',
                'deckCards' => $deckCards,
            ]),
        );

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testCreatingTheDeckItselfSucceedsRegardlessOfFormatLegality(): void
    {
        // Confirms DeckStateProcessor never 422s on format rules — it stores
        // formatErrors on the entity instead (see App\Tests\Api\DeckTest).
        $this->mockCardBatch();
        $deck = $this->createSealedDeck('bga-'.__FUNCTION__);

        $this->assertResponseStatusCodeSame(201);
        self::assertNotEmpty($deck['id']);
    }

    public function testBgaItemReturns422WithUnverifiablePoolWhenNoPoolLinked(): void
    {
        // altered-draft returns 404 when no sealed_pools row is linked to this deck id
        // yet — the realistic state for a deck that's still being built and was never
        // synced through altered-draft's own frontend flow.
        $this->mockCardBatch();
        $deck = $this->createSealedDeck('bga-'.__FUNCTION__);
        self::assertNotEmpty($deck['id']);

        $this->alteredDraftMock->setResponseFactory(
            static fn (): MockResponse => new MockResponse('', ['http_code' => 404])
        );

        $this->client->request('GET', '/api/bga/decks/'.$deck['id'], [], [], $this->authHeaders('bga-'.__FUNCTION__));

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Could not verify your sealed pool', $this->client->getResponse()->getContent());
    }

    public function testBgaItemReturns200WhenPoolCoversEveryCard(): void
    {
        // Once altered-draft actually has this deck's pool linked and every card is
        // in it, the deck IS legal: only 2 real factions (AX, LY), 1 hero, 30
        // non-hero cards — the reference-string faction prefixes are misleading OOF
        // rares, not the deck's real composition.
        $this->mockCardBatch();
        $deck = $this->createSealedDeck('bga-'.__FUNCTION__);
        self::assertNotEmpty($deck['id']);

        $pool = [];
        foreach (self::ROWS as [$ref, $qty]) {
            $pool[$ref] = $qty;
        }
        $this->alteredDraftMock->setResponseFactory(
            static fn (): MockResponse => new MockResponse(
                json_encode(['cards' => $pool]),
                ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']],
            )
        );

        $this->client->request('GET', '/api/bga/decks/'.$deck['id'], [], [], $this->authHeaders('bga-'.__FUNCTION__));

        $this->assertResponseIsSuccessful();
    }

    public function testBgaItemReturns422WhenOneCardMissingFromPool(): void
    {
        // Sanity check: pool membership genuinely gates the response — dropping just
        // one card from the mocked pool reproduces the "not in your sealed pool" 422,
        // proving the harness (and not some faction/size quirk) drives the result.
        $this->mockCardBatch();
        $deck = $this->createSealedDeck('bga-'.__FUNCTION__);
        self::assertNotEmpty($deck['id']);

        $pool = [];
        foreach (self::ROWS as [$ref, $qty]) {
            $pool[$ref] = $qty;
        }
        unset($pool['ALT_EOLE_B_LY_117_E']);
        $this->alteredDraftMock->setResponseFactory(
            static fn (): MockResponse => new MockResponse(
                json_encode(['cards' => $pool]),
                ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']],
            )
        );

        $this->client->request('GET', '/api/bga/decks/'.$deck['id'], [], [], $this->authHeaders('bga-'.__FUNCTION__));

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('not in your sealed pool', $this->client->getResponse()->getContent());
    }
}
