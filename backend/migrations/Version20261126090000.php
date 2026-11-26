<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store ordered private receipt photos with their owner until claimed by an expense.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE receipt_upload (
                id UUID PRIMARY KEY, owner_id UUID NOT NULL, request_key UUID NOT NULL,
                fingerprint VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                photos JSONB NOT NULL, debt_id UUID DEFAULT NULL,
                CONSTRAINT fk_receipt_owner FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE RESTRICT,
                CONSTRAINT fk_receipt_debt FOREIGN KEY (debt_id) REFERENCES debt (id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_receipt_request ON receipt_upload(owner_id, request_key)');
        $this->addSql('CREATE UNIQUE INDEX uniq_receipt_debt ON receipt_upload(debt_id)');
        $this->addSql('CREATE INDEX idx_receipt_created ON receipt_upload(created_at)');
        $this->addSql('CREATE INDEX idx_receipt_owner ON receipt_upload(owner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE receipt_upload');
    }
}
