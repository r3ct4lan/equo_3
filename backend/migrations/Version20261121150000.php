<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve exact expense shares after account merges.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('ALTER TABLE debt ADD hidden_by_merge BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE debt_participant ADD actual_share_amount BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE debt_participant ADD CONSTRAINT chk_debt_participant_actual_share CHECK (actual_share_amount > 0)');
        $this->addSql('ALTER TABLE transfer ADD hidden_by_merge BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE transfer ALTER COLUMN amount TYPE BIGINT');
        $this->addSql("ALTER TABLE transfer ADD CONSTRAINT chk_transfer_max_amount CHECK (amount <= CASE WHEN type = 'MANUAL' THEN 2147483647 ELSE 9007199254740991 END)");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Merged expense shares must be preserved.');
    }
}
