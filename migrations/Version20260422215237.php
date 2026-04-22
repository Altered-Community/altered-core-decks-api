<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422215237 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_draft column to deck table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck ADD is_draft BOOLEAN');
        $this->addSql('UPDATE deck SET is_draft = false');
        $this->addSql('ALTER TABLE deck ALTER is_draft SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE deck DROP is_draft');
    }
}
