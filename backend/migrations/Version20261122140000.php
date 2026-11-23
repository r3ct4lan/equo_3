<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve original operation currency and an optional pair-specific rate.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        foreach (['debt', 'transfer'] as $table) {
            $this->addSql("ALTER TABLE $table ADD currency VARCHAR(3) NOT NULL DEFAULT 'EUR', ADD manual_rate TEXT, ADD rate_currency VARCHAR(3), ADD CHECK (currency ~ '^[A-Z]{3}$'), ADD CHECK ((manual_rate IS NULL) = (rate_currency IS NULL) AND rate_currency <> currency)");
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Original currency amounts must be preserved.');
    }
}
