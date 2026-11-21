<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow session-bound Telegram deactivation confirmations.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('ALTER TABLE user_action_token DROP CONSTRAINT chk_user_action_token_purpose');
        $this->addSql("ALTER TABLE user_action_token ADD CONSTRAINT chk_user_action_token_purpose CHECK (purpose IN ('ACTIVATE_ACCOUNT', 'RESET_PASSWORD', 'CHANGE_EMAIL', 'LINK_EMAIL', 'MERGE_ACCOUNT', 'DEACTIVATE_WITH_TELEGRAM'))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Telegram deactivation confirmations must be preserved.');
    }
}
