<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store payment conversion snapshots and allocated amounts.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('ALTER TABLE transfer ADD settlement_rate TEXT, ADD credited_amount BIGINT NOT NULL DEFAULT 0 CHECK (credited_amount >= 0), ADD excess_amount BIGINT CHECK (excess_amount BETWEEN 0 AND amount)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Payment allocations must be preserved.');
    }
}
