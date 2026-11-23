<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set a default profile currency for existing and new users.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql("ALTER TABLE app_user ADD currency VARCHAR(3) NOT NULL DEFAULT 'EUR' CHECK (currency ~ '^[A-Z]{3}$')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP currency');
    }
}
