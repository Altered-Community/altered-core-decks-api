<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add name column to deck_card for card name search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck_card ADD name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck_card DROP name');
    }
}
