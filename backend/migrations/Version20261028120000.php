<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index transfer history by viewer direction and creation order.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('CREATE INDEX idx_transfer_incoming_history ON transfer (receiver_id, created_at, id)');
        $this->addSql('CREATE INDEX idx_transfer_outgoing_history ON transfer (sender_id, created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $this->addSql('DROP INDEX idx_transfer_incoming_history');
        $this->addSql('DROP INDEX idx_transfer_outgoing_history');
    }
}
