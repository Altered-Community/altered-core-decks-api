<?php

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the real migration down then up over pre-existing rows. DAMA DoctrineTestBundle
 * wraps the test in a transaction and PostgreSQL DDL is transactional, so the schema
 * change is rolled back with everything else at the end of the test.
 */
class Version20260926000000Test extends KernelTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20260926000000';

    private Connection $connection;
    private Application $application;

    protected function setUp(): void
    {
        $this->boot();
    }

    /**
     * A migration instance is frozen after it ran once, and the migrations
     * DependencyFactory caches instances per container, so each direction needs a fresh
     * kernel. DAMA keeps the same underlying connection (and transaction) across kernels.
     */
    private function boot(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->connection = static::getContainer()->get('doctrine')->getConnection();
        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);
    }

    private function migrate(string $direction): void
    {
        $tester = new CommandTester($this->application->find('doctrine:migrations:execute'));
        $tester->execute([
            'versions' => [self::VERSION],
            '--'.$direction => true,
            '--no-interaction' => true,
        ], ['interactive' => false]);
        $tester->assertCommandIsSuccessful($tester->getDisplay());
    }

    private function insertLegacyDeck(string $userId, string $createdAt, ?string $updatedAt): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->connection->executeStatement(
            'INSERT INTO deck (id, name, is_public, is_draft, created_at, updated_at, user_id, legal, view_count, upvote_count)
             VALUES (:id, :name, true, false, :c, :u, :user, false, 3, 2)',
            ['id' => $id, 'name' => 'legacy '.$id, 'c' => $createdAt, 'u' => $updatedAt, 'user' => $userId],
        );

        return $id;
    }

    public function testBackfillsExistingDecksThenEnforcesNotNull(): void
    {
        $this->migrate('down');
        $this->assertFalse($this->columnExists(), 'down() drops the column');

        $userId = Uuid::v4()->toRfc4122();
        $this->connection->executeStatement(
            'INSERT INTO "user" (id, keycloak_id, created_at, is_admin) VALUES (:id, :kc, NOW(), false)',
            ['id' => $userId, 'kc' => 'migration-test-'.$userId],
        );
        $neverEdited = $this->insertLegacyDeck($userId, '2025-01-01 10:00:00', null);
        $edited = $this->insertLegacyDeck($userId, '2025-01-01 10:00:00', '2026-03-04 05:06:07');

        $this->boot();
        $this->migrate('up');

        $rows = $this->connection->fetchAllAssociativeIndexed(
            'SELECT id, created_at, updated_at, last_modified_at, view_count, upvote_count FROM deck WHERE id IN (:a, :b)',
            ['a' => $neverEdited, 'b' => $edited],
        );

        $this->assertSame('2025-01-01 10:00:00', $rows[$neverEdited]['last_modified_at'], 'never edited → createdAt');
        $this->assertNull($rows[$neverEdited]['updated_at'], 'updated_at is left untouched');
        $this->assertSame('2026-03-04 05:06:07', $rows[$edited]['last_modified_at'], 'edited → updatedAt');
        $this->assertSame('2026-03-04 05:06:07', $rows[$edited]['updated_at']);
        $this->assertSame(3, (int) $rows[$edited]['view_count']);

        $this->assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM deck WHERE last_modified_at IS DISTINCT FROM COALESCE(updated_at, created_at)'
        ), 'every existing deck is backfilled');

        $column = $this->connection->fetchAssociative(
            "SELECT is_nullable, column_default FROM information_schema.columns WHERE table_name = 'deck' AND column_name = 'last_modified_at'"
        );
        $this->assertSame('NO', $column['is_nullable']);
        $this->assertStringContainsStringIgnoringCase('CURRENT_TIMESTAMP', (string) $column['column_default']);

        $indexDef = $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'idx_deck_public_last_modified'");
        $this->assertStringContainsString('(is_public, is_draft, last_modified_at DESC, id DESC)', (string) $indexDef);
    }

    public function testRowsInsertedWithoutTheColumnGetADefault(): void
    {
        // Simulates code from before this change inserting during a rolling deploy.
        $userId = Uuid::v4()->toRfc4122();
        $this->connection->executeStatement(
            'INSERT INTO "user" (id, keycloak_id, created_at, is_admin) VALUES (:id, :kc, NOW(), false)',
            ['id' => $userId, 'kc' => 'migration-test-'.$userId],
        );
        $id = $this->insertLegacyDeck($userId, '2026-01-01 00:00:00', null);

        $this->assertNotNull($this->connection->fetchOne('SELECT last_modified_at FROM deck WHERE id = :id', ['id' => $id]));
    }

    private function columnExists(): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'deck' AND column_name = 'last_modified_at'"
        );
    }
}
