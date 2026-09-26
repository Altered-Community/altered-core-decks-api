<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * No backfill of deck.frontier_pool: the pool an existing Frontier deck was validated against
 * is unknown (its legality may predate the current pool), so every deck starts at NULL, which
 * means "not validated against a known pool". The first app:frontier:sync-pool run records the
 * current pool and revalidates those decks. Adding a nullable column without a default is a
 * catalog-only change in PostgreSQL, so it does not rewrite the deck table.
 */
final class Version20260926000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track the Frontier pool each deck\'s legality was computed against';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE frontier_pool (id VARCHAR(64) NOT NULL, card_count INT NOT NULL, revision INT NOT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, activated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_frontier_pool_revision ON frontier_pool (revision)');
        $this->addSql('ALTER TABLE deck ADD frontier_pool VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck DROP frontier_pool');
        $this->addSql('DROP TABLE frontier_pool');
    }
}
