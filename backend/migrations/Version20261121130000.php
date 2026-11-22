<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track session-bound login methods and identity confirmations.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('ALTER TABLE app_user ADD strict_session_access BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE user_action_token DROP CONSTRAINT chk_user_action_token_purpose');
        $this->addSql("ALTER TABLE user_action_token ADD CONSTRAINT chk_user_action_token_purpose CHECK (purpose IN ('ACTIVATE_ACCOUNT', 'RESET_PASSWORD', 'CHANGE_EMAIL', 'LINK_EMAIL', 'MERGE_ACCOUNT'))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Session-bound revocation and credential confirmations must be preserved.');
    }
}
