<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the UserSession table for protected session refresh token persistence.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE user_session (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                refresh_token_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                CONSTRAINT pk_user_session PRIMARY KEY (id),
                CONSTRAINT fk_user_session_user FOREIGN KEY (user_id)
                    REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE RESTRICT,
                CONSTRAINT chk_user_session_expiry CHECK (expires_at > created_at)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_session_refresh_token_hash ON user_session (refresh_token_hash)');
        $this->addSql('CREATE INDEX idx_user_session_user ON user_session (user_id)');
        $this->addSql('CREATE INDEX idx_user_session_expires_at ON user_session (expires_at)');
        $this->addSql('CREATE INDEX idx_user_session_revoked_at ON user_session (revoked_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql('DROP TABLE user_session');
    }
}
