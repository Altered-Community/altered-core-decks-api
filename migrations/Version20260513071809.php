<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260513071809 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add legality_detail JSON column to deck table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck ADD legality_detail JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck DROP COLUMN legality_detail');
    }
}
