<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Debt aggregate, participants and Transfer ledger.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql(<<<'SQL'
CREATE TABLE debt (
 id UUID NOT NULL PRIMARY KEY,
 title TEXT NOT NULL,
 description TEXT DEFAULT NULL,
 total_amount INTEGER NOT NULL,
 payer_id UUID NOT NULL,
 created_by_id UUID NOT NULL,
 created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
 is_deleted BOOLEAN DEFAULT false NOT NULL,
 version INTEGER DEFAULT 1 NOT NULL,
 CONSTRAINT fk_debt_payer FOREIGN KEY (payer_id) REFERENCES app_user(id) ON DELETE RESTRICT,
 CONSTRAINT fk_debt_author FOREIGN KEY (created_by_id) REFERENCES app_user(id) ON DELETE RESTRICT,
 CONSTRAINT chk_debt_amount CHECK (total_amount > 0),
 CONSTRAINT chk_debt_version CHECK (version >= 1),
 CONSTRAINT chk_debt_title CHECK (length(btrim(title)) > 0)
)
SQL);
        $this->addSql('CREATE INDEX idx_debt_author ON debt (created_by_id, is_deleted, created_at, id)');
        $this->addSql('CREATE INDEX idx_debt_payer ON debt (payer_id, is_deleted, created_at, id)');
        $this->addSql('CREATE INDEX idx_debt_deleted ON debt (is_deleted)');
        $this->addSql(<<<'SQL'
CREATE TABLE debt_participant (
 id UUID NOT NULL PRIMARY KEY,
 debt_id UUID NOT NULL,
 user_id UUID NOT NULL,
 created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
 is_deleted BOOLEAN DEFAULT false NOT NULL,
 CONSTRAINT fk_debt_participant_debt FOREIGN KEY (debt_id) REFERENCES debt(id) ON DELETE RESTRICT,
 CONSTRAINT fk_debt_participant_user FOREIGN KEY (user_id) REFERENCES app_user(id) ON DELETE RESTRICT
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_debt_participant_user ON debt_participant (debt_id, user_id)');
        $this->addSql('CREATE INDEX idx_debt_participant_user ON debt_participant (user_id, is_deleted, debt_id)');
        $this->addSql('CREATE INDEX idx_debt_participant_current ON debt_participant (debt_id, is_deleted)');
        $this->addSql(<<<'SQL'
CREATE TABLE transfer (
 id UUID NOT NULL PRIMARY KEY,
 connect_id UUID NOT NULL,
 sender_id UUID NOT NULL,
 receiver_id UUID NOT NULL,
 amount INTEGER NOT NULL,
 type TEXT NOT NULL,
 created_by_id UUID DEFAULT NULL,
 created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
 is_deleted BOOLEAN DEFAULT false NOT NULL,
 version INTEGER DEFAULT 1 NOT NULL,
 debt_id UUID DEFAULT NULL,
 debt_participant_id UUID DEFAULT NULL,
 related_transfer_id UUID DEFAULT NULL,
 CONSTRAINT fk_transfer_connect FOREIGN KEY (connect_id) REFERENCES connect(id) ON DELETE RESTRICT,
 CONSTRAINT fk_transfer_sender FOREIGN KEY (sender_id) REFERENCES app_user(id) ON DELETE RESTRICT,
 CONSTRAINT fk_transfer_receiver FOREIGN KEY (receiver_id) REFERENCES app_user(id) ON DELETE RESTRICT,
 CONSTRAINT fk_transfer_author FOREIGN KEY (created_by_id) REFERENCES app_user(id) ON DELETE RESTRICT,
 CONSTRAINT fk_transfer_debt FOREIGN KEY (debt_id) REFERENCES debt(id) ON DELETE RESTRICT,
 CONSTRAINT fk_transfer_participant FOREIGN KEY (debt_participant_id) REFERENCES debt_participant(id) ON DELETE RESTRICT,
 CONSTRAINT fk_transfer_related FOREIGN KEY (related_transfer_id) REFERENCES transfer(id) ON DELETE RESTRICT,
 CONSTRAINT chk_transfer_amount CHECK (amount > 0),
 CONSTRAINT chk_transfer_sides CHECK (sender_id <> receiver_id),
 CONSTRAINT chk_transfer_version CHECK (version >= 1),
 CONSTRAINT chk_transfer_type CHECK (
 (type = 'DEBT_SHARE' AND created_by_id IS NULL AND debt_id IS NOT NULL AND debt_participant_id IS NOT NULL AND related_transfer_id IS NULL)
 OR (type = 'MANUAL' AND created_by_id IS NOT NULL AND debt_id IS NULL AND debt_participant_id IS NULL)
 )
)
SQL);
        $this->addSql("CREATE UNIQUE INDEX uniq_transfer_debt_share ON transfer (debt_participant_id) WHERE type = 'DEBT_SHARE'");
        $this->addSql('CREATE INDEX idx_transfer_history ON transfer (connect_id, created_at, id)');
        $this->addSql('CREATE INDEX idx_transfer_balance ON transfer (connect_id, is_deleted)');
        foreach (['sender', 'receiver', 'author', 'debt', 'participant', 'related'] as $name) {
            $column = match ($name) {
                'author' => 'created_by_id', 'participant' => 'debt_participant_id', 'related' => 'related_transfer_id', default => $name.'_id',
            };
            $this->addSql('CREATE INDEX idx_transfer_'.$name.' ON transfer ('.$column.')');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('DROP TABLE transfer');
        $this->addSql('DROP TABLE debt_participant');
        $this->addSql('DROP TABLE debt');
    }
}
