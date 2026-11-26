<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track recognition attempts and editable receipt drafts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE receipt_upload ADD attempt UUID, ADD state VARCHAR(12) NOT NULL DEFAULT 'pending', ADD started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD draft JSONB DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt_upload DROP attempt, DROP state, DROP started_at, DROP draft');
    }
}
