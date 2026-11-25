<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revalidate legacy snapshots and store independent HTTP freshness and validators for each feed.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql("ALTER TABLE currency_market ADD cache_policy JSONB NOT NULL DEFAULT '{}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE currency_market DROP COLUMN cache_policy');
    }
}
