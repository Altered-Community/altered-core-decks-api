<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stays transactional: the entrypoint runs `migrate --all-or-nothing`, which refuses
 * non-transactional migrations (so no CREATE INDEX CONCURRENTLY). lock_timeout makes it
 * fail fast instead of queueing behind a long query, and ADD COLUMN ... NOT NULL DEFAULT
 * is metadata-only on PostgreSQL >= 11 (no rewrite, no NOT NULL scan).
 */
final class Version20260926000000 extends AbstractMigration
{
    /** Public-listing sort indexes: column => index name. id matches findPublic()'s tie-break. */
    private const PUBLIC_SORT_INDEXES = [
        'created_at' => 'idx_deck_public_created',
        'upvote_count' => 'idx_deck_public_upvote',
        'view_count' => 'idx_deck_public_view',
    ];

    public function getDescription(): string
    {
        return 'Add deck.last_modified_at (never null, COALESCE(updated_at, created_at)); add id to the public-listing sort indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '10s'");
        $this->addSql('ALTER TABLE deck ADD last_modified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('UPDATE deck SET last_modified_at = COALESCE(updated_at, created_at)');
        $this->addSql('CREATE INDEX idx_deck_public_last_modified ON deck (is_public, is_draft, last_modified_at DESC, id DESC)');
        foreach (self::PUBLIC_SORT_INDEXES as $column => $index) {
            $this->addSql("DROP INDEX {$index}");
            $this->addSql("CREATE INDEX {$index} ON deck (is_public, is_draft, {$column} DESC, id DESC)");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::PUBLIC_SORT_INDEXES as $column => $index) {
            $this->addSql("DROP INDEX {$index}");
            $this->addSql("CREATE INDEX {$index} ON deck (is_public, is_draft, {$column} DESC)");
        }
        $this->addSql('DROP INDEX idx_deck_public_last_modified');
        $this->addSql('ALTER TABLE deck DROP last_modified_at');
    }
}
