<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds deck.last_modified_at = COALESCE(updated_at, created_at), never null.
 *
 * Stays transactional on purpose: the container entrypoint runs
 * `doctrine:migrations:migrate --all-or-nothing`, which refuses non-transactional
 * migrations (so no CREATE INDEX CONCURRENTLY and no batch-by-batch commits here).
 * To keep the time deck is locked short:
 *  - lock_timeout makes the migration fail fast (and roll back) instead of queueing
 *    behind a long-running query while every other query on deck queues behind it;
 *  - the column is added NOT NULL with a DEFAULT, which PostgreSQL >= 11 does as a
 *    metadata-only change (no table rewrite, no NOT NULL validation scan). The default
 *    also covers rows inserted during the deploy by code that doesn't know the column;
 *  - the backfill is a single UPDATE of each row, followed by the index build.
 */
final class Version20260926000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deck.last_modified_at (never null, COALESCE(updated_at, created_at)) and its public-listing sort index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '10s'");
        $this->addSql('ALTER TABLE deck ADD last_modified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('UPDATE deck SET last_modified_at = COALESCE(updated_at, created_at)');
        // Matches findPublic(): WHERE is_public AND NOT is_draft ORDER BY last_modified_at, id
        // (same shape as the other idx_deck_public_* indexes, plus id for the tie-break).
        $this->addSql('CREATE INDEX idx_deck_public_last_modified ON deck (is_public, is_draft, last_modified_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_deck_public_last_modified');
        $this->addSql('ALTER TABLE deck DROP last_modified_at');
    }
}
