<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the User, UserActionToken, IdempotencyRecord and EmailDeliveryOutbox tables for the first vertical slice.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE app_user (
                id UUID NOT NULL,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                is_active BOOLEAN NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                CONSTRAINT pk_app_user PRIMARY KEY (id),
                CONSTRAINT chk_app_user_name_not_blank CHECK (length(btrim(name)) > 0),
                CONSTRAINT chk_app_user_email_not_blank CHECK (length(btrim(email)) > 0)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_email ON app_user (email)');

        $this->addSql(<<<'SQL'
            CREATE TABLE user_action_token (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                token_hash TEXT NOT NULL,
                purpose TEXT NOT NULL,
                payload JSONB DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                invalidated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                CONSTRAINT pk_user_action_token PRIMARY KEY (id),
                CONSTRAINT fk_user_action_token_user FOREIGN KEY (user_id)
                    REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE RESTRICT,
                CONSTRAINT chk_user_action_token_purpose CHECK (
                    purpose IN ('ACTIVATE_ACCOUNT', 'RESET_PASSWORD', 'CHANGE_EMAIL')
                ),
                CONSTRAINT chk_user_action_token_expiry CHECK (expires_at > created_at),
                CONSTRAINT chk_user_action_token_terminal_state CHECK (
                    NOT (used_at IS NOT NULL AND invalidated_at IS NOT NULL)
                )
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_action_token_hash ON user_action_token (token_hash)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_user_action_token_unfinished
                ON user_action_token (user_id, purpose)
                WHERE used_at IS NULL AND invalidated_at IS NULL
            SQL);
        $this->addSql('CREATE INDEX idx_user_action_token_user ON user_action_token (user_id)');
        $this->addSql('CREATE INDEX idx_user_action_token_purpose ON user_action_token (purpose)');
        $this->addSql('CREATE INDEX idx_user_action_token_expires_at ON user_action_token (expires_at)');
        $this->addSql('CREATE INDEX idx_user_action_token_used_at ON user_action_token (used_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE idempotency_record (
                id UUID NOT NULL,
                scope TEXT NOT NULL,
                user_id UUID DEFAULT NULL,
                operation TEXT NOT NULL,
                idempotency_key TEXT NOT NULL,
                request_hash TEXT NOT NULL,
                response_status INTEGER DEFAULT NULL,
                response_body JSONB DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                CONSTRAINT pk_idempotency_record PRIMARY KEY (id),
                CONSTRAINT fk_idempotency_record_user FOREIGN KEY (user_id)
                    REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE RESTRICT,
                CONSTRAINT chk_idempotency_record_expiry CHECK (expires_at > created_at)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_idempotency_scope_operation_key
                ON idempotency_record (scope, operation, idempotency_key)
            SQL);
        $this->addSql('CREATE INDEX idx_idempotency_record_user ON idempotency_record (user_id)');
        $this->addSql('CREATE INDEX idx_idempotency_record_expires_at ON idempotency_record (expires_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE email_delivery_outbox (
                id UUID NOT NULL,
                user_action_token_id UUID NOT NULL,
                recipient_email TEXT NOT NULL,
                template_key TEXT NOT NULL,
                encrypted_payload BYTEA DEFAULT NULL,
                status TEXT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                failed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                publish_attempts INTEGER NOT NULL,
                last_error TEXT DEFAULT NULL,
                CONSTRAINT pk_email_delivery_outbox PRIMARY KEY (id),
                CONSTRAINT fk_email_delivery_outbox_token FOREIGN KEY (user_action_token_id)
                    REFERENCES user_action_token (id) ON UPDATE NO ACTION ON DELETE RESTRICT,
                CONSTRAINT chk_email_delivery_outbox_status CHECK (
                    status IN ('PENDING', 'PUBLISHED', 'SENT', 'FAILED')
                ),
                CONSTRAINT chk_email_delivery_outbox_attempts CHECK (publish_attempts >= 0),
                CONSTRAINT chk_email_delivery_outbox_available_at CHECK (available_at >= created_at),
                CONSTRAINT chk_email_delivery_outbox_terminal_state CHECK (
                    NOT (sent_at IS NOT NULL AND failed_at IS NOT NULL)
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_email_outbox_token
                ON email_delivery_outbox (user_action_token_id)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_email_outbox_pending
                ON email_delivery_outbox (available_at, created_at)
                WHERE status = 'PENDING'
            SQL);
        $this->addSql('CREATE INDEX idx_email_outbox_status ON email_delivery_outbox (status)');
        $this->addSql('CREATE INDEX idx_email_outbox_published_at ON email_delivery_outbox (published_at)');
        $this->addSql('CREATE INDEX idx_email_outbox_sent_at ON email_delivery_outbox (sent_at)');
        $this->addSql('CREATE INDEX idx_email_outbox_failed_at ON email_delivery_outbox (failed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql('DROP TABLE email_delivery_outbox');
        $this->addSql('DROP TABLE idempotency_record');
        $this->addSql('DROP TABLE user_action_token');
        $this->addSql('DROP TABLE app_user');
    }
}
