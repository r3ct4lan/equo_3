<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create permanent Connect pairs and signed ConnectInvitation lifecycle.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql(<<<'SQL'
            CREATE TABLE connect (
                id UUID NOT NULL PRIMARY KEY,
                first_user_id UUID NOT NULL,
                second_user_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                CONSTRAINT fk_connect_first_user FOREIGN KEY (first_user_id) REFERENCES app_user(id) ON DELETE RESTRICT,
                CONSTRAINT fk_connect_second_user FOREIGN KEY (second_user_id) REFERENCES app_user(id) ON DELETE RESTRICT,
                CONSTRAINT chk_connect_canonical_pair CHECK (first_user_id < second_user_id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_connect_pair ON connect (first_user_id, second_user_id)');
        $this->addSql('CREATE INDEX idx_connect_first ON connect (first_user_id, created_at, id)');
        $this->addSql('CREATE INDEX idx_connect_second ON connect (second_user_id, created_at, id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE connect_invitation (
                id UUID NOT NULL PRIMARY KEY,
                created_by_id UUID NOT NULL,
                token_hash TEXT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                accepted_by_id UUID DEFAULT NULL,
                CONSTRAINT fk_connect_invitation_author FOREIGN KEY (created_by_id) REFERENCES app_user(id) ON DELETE RESTRICT,
                CONSTRAINT fk_connect_invitation_acceptor FOREIGN KEY (accepted_by_id) REFERENCES app_user(id) ON DELETE RESTRICT,
                CONSTRAINT chk_connect_invitation_expiry CHECK (expires_at > created_at),
                CONSTRAINT chk_connect_invitation_used CHECK ((used_at IS NULL) = (accepted_by_id IS NULL)),
                CONSTRAINT chk_connect_invitation_not_self CHECK (accepted_by_id IS NULL OR accepted_by_id <> created_by_id),
                CONSTRAINT chk_connect_invitation_used_time CHECK (used_at IS NULL OR used_at >= created_at)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_connect_invitation_token_hash ON connect_invitation (token_hash)');
        $this->addSql('CREATE INDEX idx_connect_invitation_author ON connect_invitation (created_by_id, expires_at)');
        $this->addSql('CREATE INDEX idx_connect_invitation_accepted_by ON connect_invitation (accepted_by_id)');
        $this->addSql('CREATE INDEX idx_connect_invitation_expires_at ON connect_invitation (expires_at)');
        $this->addSql('CREATE INDEX idx_connect_invitation_used_at ON connect_invitation (used_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('DROP TABLE connect_invitation');
        $this->addSql('DROP TABLE connect');
    }
}
