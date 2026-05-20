<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add view_count and upvote_count to deck; create deck_upvote table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck ADD view_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE deck ADD upvote_count INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE TABLE deck_upvote (
            id UUID NOT NULL,
            deck_id UUID NOT NULL,
            user_id UUID NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('COMMENT ON COLUMN deck_upvote.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN deck_upvote.deck_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN deck_upvote.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN deck_upvote.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_DECK_UPVOTE_DECK ON deck_upvote (deck_id)');
        $this->addSql('CREATE INDEX IDX_DECK_UPVOTE_USER ON deck_upvote (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_deck_upvote_user ON deck_upvote (deck_id, user_id)');
        $this->addSql('ALTER TABLE deck_upvote ADD CONSTRAINT FK_DECK_UPVOTE_DECK FOREIGN KEY (deck_id) REFERENCES deck (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE deck_upvote ADD CONSTRAINT FK_DECK_UPVOTE_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck_upvote DROP CONSTRAINT FK_DECK_UPVOTE_DECK');
        $this->addSql('ALTER TABLE deck_upvote DROP CONSTRAINT FK_DECK_UPVOTE_USER');
        $this->addSql('DROP TABLE deck_upvote');
        $this->addSql('ALTER TABLE deck DROP view_count');
        $this->addSql('ALTER TABLE deck DROP upvote_count');
    }
}
