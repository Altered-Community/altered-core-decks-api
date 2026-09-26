<?php

namespace App\Tests\Api;

use App\Entity\Deck;
use App\Repository\DeckRepository;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260926000000;
use Firebase\JWT\JWT;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Public deck endpoints expose the author's pseudo as `user.username`, and never an email address.
 */
class DeckAuthorTest extends WebTestCase
{
    private const EMAIL_PATTERN = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $claims
     */
    private function token(string $sub, array $claims): string
    {
        return JWT::encode([
            'sub' => $sub,
            'iss' => 'dev',
            'iat' => time(),
            'exp' => time() + 3600,
            ...$claims,
        ], '$ecretf0rt3st_extended_for_hs256_tests', 'HS256');
    }

    /**
     * @param array<string, string> $claims
     */
    private function createPublicDeck(string $sub, array $claims): string
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token($sub, $claims), 'CONTENT_TYPE' => 'application/json'];
        $this->client->request('POST', '/api/decks', [], [], $headers, json_encode(['name' => 'Deck '.$sub, 'isDraft' => false]));
        self::assertResponseStatusCodeSame(201);
        $this->assertNoEmail($this->client->getResponse()->getContent());
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $headers['CONTENT_TYPE'] = 'application/merge-patch+json';
        $this->client->request('PATCH', '/api/decks/'.$id, [], [], $headers, json_encode(['isPublic' => true]));
        self::assertResponseIsSuccessful();
        $this->assertNoEmail($this->client->getResponse()->getContent());

        return $id;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} the deck from GET /api/decks/public, and the raw body
     */
    private function fetchFromPublicList(string $id): array
    {
        $this->client->request('GET', '/api/decks/public', ['itemsPerPage' => 1000]);
        self::assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();

        $found = array_values(array_filter(json_decode($body, true)['member'], fn (array $d) => $d['id'] === $id));
        self::assertCount(1, $found, 'Deck should appear in the public list');

        return [$found[0], $body];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} the deck from GET /api/decks/{id}, and the raw body
     */
    private function fetchDetail(string $id): array
    {
        $this->client->request('GET', '/api/decks/'.$id);
        self::assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();

        return [json_decode($body, true), $body];
    }

    private function assertNoEmail(string $content): void
    {
        self::assertDoesNotMatchRegularExpression(self::EMAIL_PATTERN, $content, 'Response must never contain an email address');
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertSafeUserPayload(array $user): void
    {
        self::assertSame([], array_diff(array_keys($user), ['username']), 'Only `username` may be exposed for the deck author');
    }

    /**
     * No pseudo: `username` is omitted (null values are skipped by the serializer).
     *
     * @param array<string, mixed> $user
     */
    private function assertNoUsername(array $user): void
    {
        $this->assertSafeUserPayload($user);
        self::assertNull($user['username'] ?? null);
    }

    // ── Pseudo is exposed ─────────────────────────────────────────────────────

    public function testPublicListExposesAuthorPseudo(): void
    {
        $id = $this->createPublicDeck('author-'.__FUNCTION__, [
            'pseudo' => 'PseudoJoueur',
            'preferred_username' => 'joueur@example.com',
            'email' => 'joueur@example.com',
        ]);

        [$deck, $body] = $this->fetchFromPublicList($id);

        self::assertSame(['username' => 'PseudoJoueur'], $deck['user']);
        $this->assertNoEmail($body);
    }

    public function testPublicDetailExposesAuthorPseudo(): void
    {
        $id = $this->createPublicDeck('author-'.__FUNCTION__, [
            'pseudo' => 'PseudoJoueur',
            'preferred_username' => 'joueur@example.com',
            'email' => 'joueur@example.com',
        ]);

        [$deck, $body] = $this->fetchDetail($id);

        self::assertSame(['username' => 'PseudoJoueur'], $deck['user']);
        $this->assertNoEmail($body);
    }

    // ── Email is never exposed ────────────────────────────────────────────────

    public function testNoEmailWhenPseudoMissingAndPreferredUsernameIsEmail(): void
    {
        $id = $this->createPublicDeck('author-'.__FUNCTION__, [
            'preferred_username' => 'leak@example.com',
            'name' => 'leak@example.com',
            'email' => 'leak@example.com',
        ]);

        foreach ([$this->fetchFromPublicList($id), $this->fetchDetail($id)] as [$deck, $body]) {
            $this->assertNoUsername($deck['user']);
            $this->assertNoEmail($body);
        }
    }

    public function testPseudoWithAtSignIsExposedAsIs(): void
    {
        $id = $this->createPublicDeck('author-'.__FUNCTION__, [
            'pseudo' => 'Joueur@Altered',
            'preferred_username' => 'joueur@example.com',
            'email' => 'joueur@example.com',
        ]);

        foreach ([$this->fetchFromPublicList($id), $this->fetchDetail($id)] as [$deck, $body]) {
            self::assertSame(['username' => 'Joueur@Altered'], $deck['user']);
            self::assertStringNotContainsString('joueur@example.com', $body);
        }
    }

    public function testTokenWithoutPseudoKeepsStoredPseudo(): void
    {
        $sub = 'author-'.__FUNCTION__;
        $id = $this->createPublicDeck($sub, ['pseudo' => 'PseudoJoueur', 'email' => 'joueur@example.com']);

        // A client without the `profile` scope: no pseudo, preferred_username is the email.
        $this->client->request('GET', '/api/decks', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token($sub, ['preferred_username' => 'joueur@example.com', 'email' => 'joueur@example.com']),
        ]);
        self::assertResponseIsSuccessful();

        foreach ([$this->fetchFromPublicList($id), $this->fetchDetail($id)] as [$deck, $body]) {
            self::assertSame(['username' => 'PseudoJoueur'], $deck['user']);
            $this->assertNoEmail($body);
        }
    }

    public function testLegacyUsernameMigrationClearsStoredEmail(): void
    {
        $sub = 'author-'.__FUNCTION__;
        $id = $this->createPublicDeck($sub, ['email' => 'legacy@example.com']);
        $connection = static::getContainer()->get('doctrine')->getConnection();

        // Before deck authors became public, username fell back to preferred_username (the email).
        $connection->executeStatement(
            'UPDATE "user" SET username = :username WHERE keycloak_id = :sub',
            ['username' => 'legacy@example.com', 'sub' => $sub],
        );

        require_once dirname(__DIR__, 2).'/migrations/Version20260926000000.php';
        $migration = new Version20260926000000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }

        foreach ([$this->fetchFromPublicList($id), $this->fetchDetail($id)] as [$deck, $body]) {
            $this->assertNoUsername($deck['user']);
            $this->assertNoEmail($body);
        }
    }

    public function testPublicListLoadsAuthorsInTheSameQuery(): void
    {
        $first = $this->createPublicDeck('author-'.__FUNCTION__.'-1', ['pseudo' => 'PremierAuteur']);
        $second = $this->createPublicDeck('author-'.__FUNCTION__.'-2', ['pseudo' => 'SecondAuteur']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $decks = static::getContainer()->get(DeckRepository::class)->findPublic(1, 1000);

        $mine = array_filter($decks, fn (Deck $d) => in_array((string) $d->getId(), [$first, $second], true));
        self::assertCount(2, $mine);
        foreach ($decks as $deck) {
            // A lazy author would cost one query per deck when the list is serialized.
            self::assertFalse($em->isUninitializedObject($deck->getUser()), 'Authors must be hydrated with the decks');
        }
    }

    // ── OpenAPI ───────────────────────────────────────────────────────────────

    public function testOpenApiDeckAuthorSchemaExposesOnlyUsername(): void
    {
        $this->client->request('GET', '/api/docs.json');
        self::assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();
        $schemas = json_decode($body, true)['components']['schemas'];

        foreach (['User-deck.read', 'User-deck.read_deck.read.detail'] as $name) {
            self::assertArrayHasKey($name, $schemas);
            self::assertSame(['username'], array_keys($schemas[$name]['properties'] ?? []), $name.' must only expose `username`');
        }

        foreach ($schemas as $name => $schema) {
            if (str_starts_with($name, 'User')) {
                self::assertArrayNotHasKey('email', $schema['properties'] ?? [], $name.' must not document `email`');
            }
        }

        $this->assertNoEmail($body);
    }
}
