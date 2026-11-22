<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prevent an absorbed profile from being reactivated.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('ALTER TABLE app_user ADD merged_into_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT fk_app_user_merge FOREIGN KEY (merged_into_id) REFERENCES app_user(id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT chk_app_user_login_method');
        $this->addSql(<<<'SQL'
            ALTER TABLE app_user ADD CONSTRAINT chk_app_user_login_method CHECK (
                (merged_into_id IS NULL AND (email IS NOT NULL OR telegram_id IS NOT NULL))
                OR (merged_into_id IS NOT NULL AND merged_into_id <> id AND NOT is_active AND email IS NULL AND telegram_id IS NULL)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Absorbed profiles and their history must be preserved.');
    }
}
