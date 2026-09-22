<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speed up the public/community deck listing: index is_public/is_draft with each sort column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_deck_public_created ON deck (is_public, is_draft, created_at DESC)');
        $this->addSql('CREATE INDEX idx_deck_public_upvote ON deck (is_public, is_draft, upvote_count DESC)');
        $this->addSql('CREATE INDEX idx_deck_public_view ON deck (is_public, is_draft, view_count DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_deck_public_view');
        $this->addSql('DROP INDEX idx_deck_public_upvote');
        $this->addSql('DROP INDEX idx_deck_public_created');
    }
}
