<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist confirmed receipt allocations and allow zero shares for authors without purchases.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt_upload ADD allocation JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE debt_participant DROP CONSTRAINT chk_debt_participant_actual_share, ADD CONSTRAINT chk_debt_participant_actual_share CHECK (actual_share_amount >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE debt_participant DROP CONSTRAINT chk_debt_participant_actual_share, ADD CONSTRAINT chk_debt_participant_actual_share CHECK (actual_share_amount > 0)');
        $this->addSql('ALTER TABLE receipt_upload DROP allocation');
    }
}
