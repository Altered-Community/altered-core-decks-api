<?php

namespace App\Tests\Api;

use Doctrine\DBAL\Connection;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * lastModifiedAt: never null, = COALESCE(updatedAt, createdAt), sortable on
 * GET /api/decks/public and GET /api/decks with a stable id tie-break.
 */
class DeckLastModifiedAtTest extends WebTestCase
{
    private const HERO_LY = '{"hero": {"reference": "ALT_CORE_B_LY_1_C"}}';

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

    private function authHeaders(string $sub): array
    {
        $token = JWT::encode([
            'sub' => $sub,
            'preferred_username' => 'testuser',
            'email' => 'test@test.com',
            'iss' => 'dev',
            'iat' => time(),
            'exp' => time() + 3600,
        ], '$ecretf0rt3st_extended_for_hs256_tests', 'HS256');

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    private function request(string $method, string $uri, ?string $sub = null, array $params = [], ?array $body = null): array
    {
        $headers = null !== $sub ? $this->authHeaders($sub) : ['CONTENT_TYPE' => 'application/json'];
        if ('PATCH' === $method) {
            $headers['CONTENT_TYPE'] = 'application/merge-patch+json';
        }

        $this->client->request($method, $uri, $params, [], $headers, null !== $body ? json_encode($body) : null);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function createDeck(string $sub, string $name, bool $public = true): array
    {
        $deck = $this->request('POST', '/api/decks', $sub, body: ['name' => $name, 'isDraft' => false, 'isPublic' => $public]);
        $this->assertResponseStatusCodeSame(201);

        return $deck;
    }

    private function connection(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }

    /**
     * Rewrites a deck's dates directly in the DB so tests control ordering without
     * sleeping. Keeps the lastModifiedAt invariant.
     */
    private function setDates(string $id, string $createdAt, ?string $updatedAt): void
    {
        $this->connection()->executeStatement(
            'UPDATE deck SET created_at = :c, updated_at = :u, last_modified_at = COALESCE(CAST(:u AS timestamp), CAST(:c AS timestamp)) WHERE id = :id',
            ['c' => $createdAt, 'u' => $updatedAt, 'id' => $id],
        );
    }

    private function setStats(array $decks, array $labels, string $statsJson): void
    {
        foreach ($labels as $label) {
            $this->connection()->executeStatement(
                'UPDATE deck SET stats = CAST(:stats AS json) WHERE id = :id',
                ['stats' => $statsJson, 'id' => $decks[$label]['id']],
            );
        }
    }

    /**
     * Inserts seven public decks named "<marker> …" straight into the DB (the POST path is
     * covered separately), owned by the user that logs in as $sub. The name filter
     * isolates them. They include:
     *  - two never-edited decks (updatedAt null), one created long ago;
     *  - three decks sharing the exact same lastModifiedAt (tie-break on id);
     *  - edited decks whose createdAt order differs from their lastModifiedAt order.
     *
     * @return array{0: string, 1: array<string, array{id: string, createdAt: string, updatedAt: ?string, lastModifiedAt: string}>} [marker, decks by label]
     */
    private function seedDecks(string $sub): array
    {
        $marker = 'LMA'.substr(md5($sub), 0, 8);
        $userId = Uuid::v4()->toRfc4122();
        $this->connection()->executeStatement(
            'INSERT INTO "user" (id, keycloak_id, created_at, is_admin) VALUES (:id, :sub, NOW(), false)',
            ['id' => $userId, 'sub' => $sub],
        );

        $plan = [
            'old-never-edited' => ['2025-01-01 10:00:00', null],
            'recent-never-edited' => ['2026-06-01 10:00:00', null],
            'tie-a' => ['2025-02-01 10:00:00', '2026-05-01 12:00:00'],
            'tie-b' => ['2025-03-01 10:00:00', '2026-05-01 12:00:00'],
            'tie-c' => ['2025-04-01 10:00:00', '2026-05-01 12:00:00'],
            'edited-latest' => ['2025-01-15 10:00:00', '2026-07-01 08:00:00'],
            'edited-early' => ['2026-01-01 10:00:00', '2026-01-02 10:00:00'],
        ];

        $decks = [];
        foreach ($plan as $label => [$createdAt, $updatedAt]) {
            $deck = [
                'id' => Uuid::v4()->toRfc4122(),
                'createdAt' => $createdAt,
                'updatedAt' => $updatedAt,
                'lastModifiedAt' => $updatedAt ?? $createdAt,
            ];
            $this->connection()->executeStatement(
                'INSERT INTO deck (id, name, is_public, is_draft, created_at, updated_at, last_modified_at, user_id, legal, view_count, upvote_count)
                 VALUES (:id, :name, true, false, :c, :u, :lm, :user, false, 0, 0)',
                ['id' => $deck['id'], 'name' => $marker.' '.$label, 'c' => $createdAt, 'u' => $updatedAt, 'lm' => $deck['lastModifiedAt'], 'user' => $userId],
            );
            $decks[$label] = $deck;
        }

        return [$marker, $decks];
    }

    /**
     * Expected order computed independently of the API: $field then id, same direction.
     * A null $field value sorts first in 'desc' (PostgreSQL NULLS FIRST for DESC).
     *
     * @param array<string, array<string, ?string>> $decks
     *
     * @return string[]
     */
    private function expectedOrder(array $decks, string $dir, string $field = 'lastModifiedAt'): array
    {
        $rows = array_values($decks);
        usort($rows, static function (array $a, array $b) use ($dir, $field): int {
            $cmp = [null === $a[$field], $a[$field], $a['id']] <=> [null === $b[$field], $b[$field], $b['id']];

            return 'asc' === $dir ? $cmp : -$cmp;
        });

        return array_column($rows, 'id');
    }

    /**
     * Walks every page of the public listing and returns the ids in the order served.
     *
     * @return string[]
     */
    private function walkPublicPages(array $params, int $itemsPerPage): array
    {
        $ids = [];
        $page = 1;
        do {
            $data = $this->request('GET', '/api/decks/public', params: $params + ['page' => $page, 'itemsPerPage' => $itemsPerPage]);
            $this->assertResponseIsSuccessful();
            $this->assertLessThanOrEqual($itemsPerPage, count($data['member']));
            array_push($ids, ...array_column($data['member'], 'id'));
            $page = $data['nextPage'];
        } while (null !== $page);

        return $ids;
    }

    private function byLastModified(string $marker, string $dir): array
    {
        return ['name' => $marker, 'order' => ['lastModifiedAt' => $dir]];
    }

    // ── Value on creation / edit ─────────────────────────────────────────────

    public function testLastModifiedAtEqualsCreatedAtOnCreation(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->createDeck($sub, 'Deck '.__FUNCTION__, public: false);

        $this->assertArrayHasKey('lastModifiedAt', $deck);
        $this->assertSame($deck['createdAt'], $deck['lastModifiedAt']);
        $this->assertNull($deck['updatedAt'], 'updatedAt keeps its historical null-until-edited behaviour');

        $read = $this->request('GET', '/api/decks/'.$deck['id'], $sub);
        $this->assertResponseIsSuccessful();
        $this->assertSame($read['createdAt'], $read['lastModifiedAt']);
        $this->assertNull($read['updatedAt']);
    }

    public function testLastModifiedAtFollowsUpdatedAtOnEdit(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->createDeck($sub, 'Deck '.__FUNCTION__, public: false);
        $this->setDates($deck['id'], '2025-01-01 10:00:00', null);

        $patched = $this->request('PATCH', '/api/decks/'.$deck['id'], $sub, body: ['name' => 'Renamed '.__FUNCTION__]);
        $this->assertResponseIsSuccessful();
        $this->assertNotNull($patched['updatedAt']);
        $this->assertSame($patched['updatedAt'], $patched['lastModifiedAt']);
        $this->assertGreaterThan(new \DateTimeImmutable('2025-01-01 10:00:00'), new \DateTimeImmutable($patched['lastModifiedAt']));

        $read = $this->request('GET', '/api/decks/'.$deck['id'], $sub);
        $this->assertSame('2025-01-01T10:00:00+00:00', $read['createdAt'], 'createdAt never moves');
        $this->assertSame($read['updatedAt'], $read['lastModifiedAt']);
    }

    public function testLastModifiedAtIsReadOnly(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $deck = $this->request('POST', '/api/decks', $sub, body: [
            'name' => 'Deck '.__FUNCTION__,
            'isDraft' => true,
            'lastModifiedAt' => '2000-01-01T00:00:00+00:00',
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($deck['createdAt'], $deck['lastModifiedAt']);

        $patched = $this->request('PATCH', '/api/decks/'.$deck['id'], $sub, body: ['lastModifiedAt' => '2000-01-01T00:00:00+00:00']);
        $this->assertResponseIsSuccessful();
        $this->assertNotSame('2000-01-01T00:00:00+00:00', $patched['lastModifiedAt']);
        $this->assertSame($patched['updatedAt'], $patched['lastModifiedAt']);
    }

    public function testUpvoteAndViewDoNotChangeDates(): void
    {
        $owner = 'owner-'.__FUNCTION__;
        $voter = 'voter-'.__FUNCTION__;
        $deck = $this->createDeck($owner, 'Deck '.__FUNCTION__);
        $this->setDates($deck['id'], '2025-01-01 10:00:00', '2025-02-01 10:00:00');

        $this->request('POST', '/api/decks/'.$deck['id'].'/upvote', $voter);
        $this->assertResponseIsSuccessful();
        $this->request('POST', '/api/decks/'.$deck['id'].'/upvote', $voter); // un-upvote
        $this->request('POST', '/api/decks/'.$deck['id'].'/upvote', $voter);

        // Anonymous GETs of a public deck increment viewCount.
        $this->request('GET', '/api/decks/'.$deck['id']);
        $read = $this->request('GET', '/api/decks/'.$deck['id']);

        $this->assertSame(1, $read['upvoteCount']);
        $this->assertSame(2, $read['viewCount']);
        $this->assertSame('2025-02-01T10:00:00+00:00', $read['lastModifiedAt']);
        $this->assertSame('2025-02-01T10:00:00+00:00', $read['updatedAt']);
    }

    public function testUpvoteOnNeverEditedDeckKeepsUpdatedAtNull(): void
    {
        $deck = $this->createDeck('owner-'.__FUNCTION__, 'Deck '.__FUNCTION__);
        $this->setDates($deck['id'], '2025-01-01 10:00:00', null);

        $this->request('POST', '/api/decks/'.$deck['id'].'/upvote', 'voter-'.__FUNCTION__);
        $read = $this->request('GET', '/api/decks/'.$deck['id']);

        $this->assertNull($read['updatedAt']);
        $this->assertSame('2025-01-01T10:00:00+00:00', $read['lastModifiedAt']);
    }

    // ── GET /api/decks/public ────────────────────────────────────────────────

    /**
     * Page sizes: one deck per page (every boundary, incl. inside the tie group), a size
     * that splits the tie group across pages, and everything on one page. Comparing to the
     * exact expected order also proves ties are ordered by id and nothing is skipped or repeated.
     */
    public function testPublicOrderByLastModifiedAtPaginatesWithoutGapsOrDuplicates(): void
    {
        [$marker, $decks] = $this->seedDecks('user-'.__FUNCTION__);

        foreach (['desc', 'asc'] as $dir) {
            foreach ([1, 3, 100] as $itemsPerPage) {
                $this->assertSame(
                    $this->expectedOrder($decks, $dir),
                    $this->walkPublicPages($this->byLastModified($marker, $dir), $itemsPerPage),
                    "order[lastModifiedAt]=$dir, itemsPerPage=$itemsPerPage",
                );
            }
        }
    }

    public function testPublicOrderByLastModifiedAtCombinesWithFilters(): void
    {
        $sub = 'user-'.__FUNCTION__;
        [$marker, $decks] = $this->seedDecks($sub);

        $standard = ['tie-a', 'edited-early', 'old-never-edited'];
        foreach ($standard as $label) {
            $this->connection()->executeStatement("UPDATE deck SET format = 'standard' WHERE id = :id", ['id' => $decks[$label]['id']]);
        }
        $withHero = ['tie-a', 'old-never-edited'];
        $this->setStats($decks, $withHero, self::HERO_LY);
        // A private deck and a draft with the marker must never appear.
        $private = $this->createDeck($sub, $marker.' private', public: false);
        $draft = $this->request('POST', '/api/decks', $sub, body: ['name' => $marker.' draft', 'isDraft' => true, 'isPublic' => true]);

        $ids = $this->walkPublicPages($this->byLastModified($marker, 'desc') + ['format' => 'standard'], 2);
        $this->assertSame($this->expectedOrder(array_intersect_key($decks, array_flip($standard)), 'desc'), $ids);

        $ids = $this->walkPublicPages($this->byLastModified($marker, 'asc') + ['faction' => 'LY', 'hero' => 'ALT_CORE_B_LY_1_C'], 1);
        $this->assertSame($this->expectedOrder(array_intersect_key($decks, array_flip($withHero)), 'asc'), $ids);

        $all = $this->walkPublicPages($this->byLastModified($marker, 'desc'), 100);
        $this->assertSame($this->expectedOrder($decks, 'desc'), $all);
        $this->assertNotContains($private['id'], $all);
        $this->assertNotContains($draft['id'], $all);
    }

    public function testPublicOrderByUpdatedAtIsUnchanged(): void
    {
        [$marker, $decks] = $this->seedDecks('user-'.__FUNCTION__);

        // order[updatedAt]=desc keeps PostgreSQL's NULLS FIRST: never-edited decks still come
        // first (that is the behaviour lastModifiedAt exists to avoid), then edited decks.
        $ids = $this->walkPublicPages(['name' => $marker, 'order' => ['updatedAt' => 'desc']], 2);
        $this->assertSame($this->expectedOrder($decks, 'desc', 'updatedAt'), $ids);
        $this->assertSame($decks['edited-latest']['id'], $ids[2]);

        $data = $this->request('GET', '/api/decks/public', params: ['name' => $marker, 'itemsPerPage' => 100]);
        foreach ($data['member'] as $deck) {
            $this->assertSame($deck['updatedAt'] ?? $deck['createdAt'], $deck['lastModifiedAt']);
        }
    }

    public function testPublicDefaultOrderIsUnchanged(): void
    {
        [$marker, $decks] = $this->seedDecks('user-'.__FUNCTION__);

        // No order param → createdAt DESC.
        $this->assertSame($this->expectedOrder($decks, 'desc', 'createdAt'), $this->walkPublicPages(['name' => $marker], 3));
    }

    // ── GET /api/decks (my decks) ────────────────────────────────────────────

    /**
     * @return string[]
     */
    private function myDeckIds(string $sub, array $params = []): array
    {
        $data = $this->request('GET', '/api/decks', $sub, $params);
        $this->assertResponseIsSuccessful();

        return array_column($data, 'id');
    }

    public function testMyDecksOrderByLastModifiedAt(): void
    {
        $sub = 'user-'.__FUNCTION__;
        [, $decks] = $this->seedDecks($sub);

        $this->assertSame($this->expectedOrder($decks, 'desc'), $this->myDeckIds($sub, ['order' => ['lastModifiedAt' => 'desc']]));
        $this->assertSame($this->expectedOrder($decks, 'asc'), $this->myDeckIds($sub, ['order' => ['lastModifiedAt' => 'asc']]));
    }

    public function testMyDecksOrderByLastModifiedAtCombinesWithFactionFilter(): void
    {
        $sub = 'user-'.__FUNCTION__;
        [, $decks] = $this->seedDecks($sub);
        $mu = ['tie-b', 'recent-never-edited', 'edited-latest'];
        $this->setStats($decks, $mu, '{"hero": {"reference": "ALT_CORE_B_MU_2_C"}}');

        $this->assertSame(
            $this->expectedOrder(array_intersect_key($decks, array_flip($mu)), 'asc'),
            $this->myDeckIds($sub, ['faction' => 'MU', 'order' => ['lastModifiedAt' => 'asc']]),
        );
    }

    public function testMyDecksDefaultOrderIsUnchanged(): void
    {
        $sub = 'user-'.__FUNCTION__;
        [, $decks] = $this->seedDecks($sub);

        // Historical order: updated_at DESC (NULLs first), now with an id tie-break.
        $expected = $this->expectedOrder($decks, 'desc', 'updatedAt');

        $this->assertSame($expected, $this->myDeckIds($sub));
        // Other order keys are still ignored on this route (parsing matrix: DeckCollectionProviderTest).
        $this->assertSame($expected, $this->myDeckIds($sub, ['order' => ['name' => 'asc']]));
    }
}
