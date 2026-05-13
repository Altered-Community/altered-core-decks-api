<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260512211957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add legal column to deck table (default false)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck ADD legal BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck DROP COLUMN legal');
    }
}
