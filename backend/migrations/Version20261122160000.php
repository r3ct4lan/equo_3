<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the order in which payments were linked to each share.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('ALTER TABLE transfer ADD settlement_position BIGINT');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Payment allocations must be preserved.');
    }
}
