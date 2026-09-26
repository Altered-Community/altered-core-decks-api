<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clear user.username filled before deck authors became public: it fell back to preferred_username (the email) or name';
    }

    public function up(Schema $schema): void
    {
        // Pseudos come back on the next authenticated request, or with app:users:sync-pseudos.
        $this->addSql('UPDATE "user" SET username = NULL');
    }

    public function down(Schema $schema): void
    {
    }
}
