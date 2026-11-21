<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow a verified Telegram identity as an account login method.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('ALTER TABLE app_user ADD telegram_id BIGINT, ALTER email DROP NOT NULL, ALTER password_hash DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_telegram_id ON app_user (telegram_id)');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT chk_app_user_telegram_id CHECK (telegram_id BETWEEN 1 AND 4503599627370495)');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT chk_app_user_password_credentials CHECK ((email IS NULL) = (password_hash IS NULL))');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT chk_app_user_login_method CHECK (email IS NOT NULL OR telegram_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->abortIf((bool) $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM app_user WHERE telegram_id IS NOT NULL)'), 'Telegram identities cannot be removed by rollback.');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT chk_app_user_login_method, DROP CONSTRAINT chk_app_user_password_credentials');
        $this->addSql('ALTER TABLE app_user DROP COLUMN telegram_id, ALTER email SET NOT NULL, ALTER password_hash SET NOT NULL');
    }
}
