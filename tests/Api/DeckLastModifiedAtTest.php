<?php

namespace App\Tests\Api;

use Doctrine\DBAL\Connection;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * lastModifiedAt: never null, = COALESCE(updatedAt, createdAt), sortable on
 * GET /api/decks/public and GET /api/decks with a stable id tie-break.
 */
class DeckLastModifiedAtTest extends WebTestCase
{
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
     * Rewrites a deck's dates directly in the DB so tests control ordering, ties and
     * never-edited decks without sleeping. Keeps the lastModifiedAt invariant.
     */
    private function setDates(string $id, string $createdAt, ?string $updatedAt): void
    {
        $this->connection()->executeStatement(
            'UPDATE deck SET created_at = :c, updated_at = :u, last_modified_at = COALESCE(CAST(:u AS timestamp), CAST(:c AS timestamp)) WHERE id = :id',
            ['c' => $createdAt, 'u' => $updatedAt, 'id' => $id],
        );
    }

    /**
     * Seven public decks named "<marker> …" (so the name filter isolates them), with:
     *  - two never-edited decks (updatedAt null), one created long ago;
     *  - three decks sharing the exact same lastModifiedAt (tie-break on id);
     *  - edited decks whose createdAt order differs from their lastModifiedAt order.
     *
     * @return array<string, array{id: string, lastModifiedAt: string}> keyed by label
     */
    private function seedDecks(string $sub, string $marker): array
    {
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
            $deck = $this->createDeck($sub, $marker.' '.$label);
            $this->setDates($deck['id'], $createdAt, $updatedAt);
            $decks[$label] = ['id' => $deck['id'], 'lastModifiedAt' => $updatedAt ?? $createdAt];
        }

        return $decks;
    }

    /**
     * Expected order computed independently of the API: lastModifiedAt then id, same direction.
     *
     * @param array<string, array{id: string, lastModifiedAt: string}> $decks
     *
     * @return string[]
     */
    private function expectedOrder(array $decks, string $dir): array
    {
        $rows = array_values($decks);
        usort($rows, static function (array $a, array $b) use ($dir): int {
            $cmp = [$a['lastModifiedAt'], $a['id']] <=> [$b['lastModifiedAt'], $b['id']];

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
            foreach ($data['member'] as $deck) {
                $ids[] = $deck['id'];
            }
            $page = $data['nextPage'];
        } while (null !== $page);

        return $ids;
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

    public function testPublicOrderByLastModifiedAtPaginatesWithoutGapsOrDuplicates(): void
    {
        $marker = 'LMA'.substr(md5(__FUNCTION__), 0, 8);
        $decks = $this->seedDecks('user-'.__FUNCTION__, $marker);

        foreach (['desc', 'asc'] as $dir) {
            $expected = $this->expectedOrder($decks, $dir);

            foreach ([1, 2, 3, 7, 100] as $itemsPerPage) {
                $ids = $this->walkPublicPages(['name' => $marker, 'order' => ['lastModifiedAt' => $dir]], $itemsPerPage);

                $this->assertSame($expected, $ids, "order[lastModifiedAt]=$dir, itemsPerPage=$itemsPerPage");
                $this->assertSame(count($decks), count(array_unique($ids)), 'no duplicates');
            }
        }

        // Never-edited decks no longer jump to the top of a "most recently modified" listing.
        $desc = $this->expectedOrder($decks, 'desc');
        $this->assertSame($decks['edited-latest']['id'], $desc[0]);
        $this->assertSame($decks['old-never-edited']['id'], end($desc));
    }

    public function testPublicLastModifiedAtTiesAreStableAcrossRequests(): void
    {
        $marker = 'LMA'.substr(md5(__FUNCTION__), 0, 8);
        $decks = $this->seedDecks('user-'.__FUNCTION__, $marker);
        $ties = [$decks['tie-a']['id'], $decks['tie-b']['id'], $decks['tie-c']['id']];

        $first = $this->walkPublicPages(['name' => $marker, 'order' => ['lastModifiedAt' => 'desc']], 1);
        for ($i = 0; $i < 3; ++$i) {
            $this->assertSame($first, $this->walkPublicPages(['name' => $marker, 'order' => ['lastModifiedAt' => 'desc']], 1));
        }

        $tiedInResponse = array_values(array_filter($first, static fn (string $id): bool => in_array($id, $ties, true)));
        $sortedTies = $ties;
        rsort($sortedTies);
        $this->assertSame($sortedTies, $tiedInResponse, 'tied decks are ordered by id, same direction');
    }

    public function testPublicOrderByLastModifiedAtCombinesWithFilters(): void
    {
        $marker = 'LMA'.substr(md5(__FUNCTION__), 0, 8);
        $decks = $this->seedDecks('user-'.__FUNCTION__, $marker);

        // Put three of them in "standard" format and give two of them the same hero.
        $conn = $this->connection();
        foreach (['tie-a', 'edited-early', 'old-never-edited'] as $label) {
            $conn->executeStatement("UPDATE deck SET format = 'standard' WHERE id = :id", ['id' => $decks[$label]['id']]);
        }
        foreach (['tie-a', 'old-never-edited'] as $label) {
            $conn->executeStatement(
                "UPDATE deck SET stats = '{\"hero\": {\"reference\": \"ALT_CORE_B_LY_1_C\"}}'::json WHERE id = :id",
                ['id' => $decks[$label]['id']],
            );
        }
        // A private deck and a draft with the marker must never appear.
        $private = $this->createDeck('user-'.__FUNCTION__, $marker.' private', public: false);
        $draft = $this->request('POST', '/api/decks', 'user-'.__FUNCTION__, body: ['name' => $marker.' draft', 'isDraft' => true, 'isPublic' => true]);

        $standard = array_intersect_key($decks, array_flip(['tie-a', 'edited-early', 'old-never-edited']));
        $ids = $this->walkPublicPages(['name' => $marker, 'format' => 'standard', 'order' => ['lastModifiedAt' => 'desc']], 2);
        $this->assertSame($this->expectedOrder($standard, 'desc'), $ids);

        $withHero = array_intersect_key($decks, array_flip(['tie-a', 'old-never-edited']));
        $ids = $this->walkPublicPages(['name' => $marker, 'faction' => 'LY', 'hero' => 'ALT_CORE_B_LY_1_C', 'order' => ['lastModifiedAt' => 'asc']], 1);
        $this->assertSame($this->expectedOrder($withHero, 'asc'), $ids);

        $all = $this->walkPublicPages(['name' => $marker, 'order' => ['lastModifiedAt' => 'desc']], 3);
        $this->assertNotContains($private['id'], $all);
        $this->assertNotContains($draft['id'], $all);
        $data = $this->request('GET', '/api/decks/public', params: ['name' => $marker, 'order' => ['lastModifiedAt' => 'desc']]);
        $this->assertSame(count($decks), $data['totalItems']);
    }

    public function testPublicOrderByUpdatedAtIsUnchanged(): void
    {
        $marker = 'LMA'.substr(md5(__FUNCTION__), 0, 8);
        $decks = $this->seedDecks('user-'.__FUNCTION__, $marker);

        // order[updatedAt]=desc keeps PostgreSQL's NULLS FIRST: never-edited decks still come
        // first (that is the behaviour lastModifiedAt exists to avoid), then edited decks.
        $ids = $this->walkPublicPages(['name' => $marker, 'order' => ['updatedAt' => 'desc']], 2);
        $neverEdited = [$decks['old-never-edited']['id'], $decks['recent-never-edited']['id']];
        rsort($neverEdited);
        $this->assertSame($neverEdited, array_slice($ids, 0, 2), 'NULL updatedAt first, tie broken by id');
        $this->assertSame($decks['edited-latest']['id'], $ids[2]);

        $data = $this->request('GET', '/api/decks/public', params: ['name' => $marker, 'itemsPerPage' => 100]);
        foreach ($data['member'] as $deck) {
            $this->assertArrayHasKey('updatedAt', $deck);
            $this->assertNotNull($deck['lastModifiedAt']);
            $this->assertSame($deck['updatedAt'] ?? $deck['createdAt'], $deck['lastModifiedAt']);
        }
    }

    public function testPublicDefaultOrderIsUnchanged(): void
    {
        $marker = 'LMA'.substr(md5(__FUNCTION__), 0, 8);
        $decks = $this->seedDecks('user-'.__FUNCTION__, $marker);

        // No order param → createdAt DESC.
        $rows = array_map(
            fn (string $label): array => ['id' => $decks[$label]['id'], 'createdAt' => $this->connection()->fetchOne('SELECT created_at FROM deck WHERE id = :id', ['id' => $decks[$label]['id']])],
            array_keys($decks),
        );
        usort($rows, static fn (array $a, array $b): int => [$b['createdAt'], $b['id']] <=> [$a['createdAt'], $a['id']]);

        $this->assertSame(array_column($rows, 'id'), $this->walkPublicPages(['name' => $marker], 3));
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
        $decks = $this->seedDecks($sub, 'Mine');

        $this->assertSame($this->expectedOrder($decks, 'desc'), $this->myDeckIds($sub, ['order' => ['lastModifiedAt' => 'desc']]));
        $this->assertSame($this->expectedOrder($decks, 'asc'), $this->myDeckIds($sub, ['order' => ['lastModifiedAt' => 'asc']]));
        $this->assertSame($this->expectedOrder($decks, 'desc'), $this->myDeckIds($sub, ['order' => ['lastModifiedAt' => 'DESC']]));
    }

    public function testMyDecksOrderByLastModifiedAtCombinesWithFactionFilter(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $decks = $this->seedDecks($sub, 'Mine');
        foreach (['tie-b', 'recent-never-edited', 'edited-latest'] as $label) {
            $this->connection()->executeStatement(
                "UPDATE deck SET stats = '{\"hero\": {\"reference\": \"ALT_CORE_B_MU_2_C\"}}'::json WHERE id = :id",
                ['id' => $decks[$label]['id']],
            );
        }

        $mu = array_intersect_key($decks, array_flip(['tie-b', 'recent-never-edited', 'edited-latest']));
        $this->assertSame($this->expectedOrder($mu, 'asc'), $this->myDeckIds($sub, ['faction' => 'MU', 'order' => ['lastModifiedAt' => 'asc']]));
    }

    public function testMyDecksDefaultOrderIsUnchanged(): void
    {
        $sub = 'user-'.__FUNCTION__;
        $decks = $this->seedDecks($sub, 'Mine');

        // Historical order: updated_at DESC (NULLs first), now with an id tie-break.
        $rows = [];
        foreach ($decks as $label => $deck) {
            $updatedAt = str_contains($label, 'never-edited') ? null : $deck['lastModifiedAt'];
            $rows[] = ['id' => $deck['id'], 'updatedAt' => $updatedAt];
        }
        usort($rows, static function (array $a, array $b): int {
            if ((null === $a['updatedAt']) !== (null === $b['updatedAt'])) {
                return null === $a['updatedAt'] ? -1 : 1;
            }

            return [$b['updatedAt'], $b['id']] <=> [$a['updatedAt'], $a['id']];
        });
        $expected = array_column($rows, 'id');

        $this->assertSame($expected, $this->myDeckIds($sub));
        // Other order keys and malformed values are still ignored on this route.
        $this->assertSame($expected, $this->myDeckIds($sub, ['order' => ['name' => 'asc']]));
        $this->assertSame($expected, $this->myDeckIds($sub, ['order' => ['lastModifiedAt' => 'sideways']]));
        $this->assertSame($expected, $this->myDeckIds($sub, ['order' => 'lastModifiedAt']));
    }
}
