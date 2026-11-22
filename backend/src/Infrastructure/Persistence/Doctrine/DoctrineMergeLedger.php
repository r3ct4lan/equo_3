<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\IdentityAccess\Application\Port\AccountMergeLedgerPort;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineMergeLedger implements AccountMergeLedgerPort
{
    public function __construct(private Connection $connection)
    {
    }

    public function apply(string $primary, string $joined, DateTimeImmutable $now): void
    {
        $connections = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, CASE WHEN first_user_id = :joined THEN second_user_id ELSE first_user_id END AS other_id
            FROM connect
            WHERE NOT hidden_by_merge AND (first_user_id = :joined OR second_user_id = :joined)
            ORDER BY id
            FOR UPDATE
            SQL, ['joined' => $joined]);
        foreach ($connections as $row) {
            $id = (string) $row['id'];
            $other = (string) $row['other_id'];
            if ($other === $primary) {
                $this->hideInternalConnection($id);
            } else {
                $this->moveConnection($id, $other, $primary, $joined);
            }
        }

        $this->connection->executeStatement(<<<'SQL'
            UPDATE connect_invitation SET created_by_id = :primary
            WHERE created_by_id = :joined AND used_at IS NULL AND expires_at > :now
            SQL, ['primary' => $primary, 'joined' => $joined, 'now' => $now->format('Y-m-d H:i:s.uP')]);
    }

    private function hideInternalConnection(string $id): void
    {
        $this->connection->executeStatement(
            'UPDATE transfer SET hidden_by_merge = true, is_deleted = true, version = version + 1 WHERE connect_id = ? AND NOT hidden_by_merge',
            [$id],
        );
        $this->connection->executeStatement('UPDATE connect SET hidden_by_merge = true WHERE id = ?', [$id]);
    }

    private function moveConnection(string $sourceId, string $other, string $primary, string $joined): void
    {
        $first = min($primary, $other);
        $second = max($primary, $other);
        $existing = $this->connection->fetchOne(
            'SELECT id FROM connect WHERE first_user_id = ? AND second_user_id = ? FOR UPDATE',
            [$first, $second],
        );
        $destinationId = is_string($existing) ? $existing : $sourceId;
        if ($destinationId === $sourceId) {
            $this->connection->update('connect', ['first_user_id' => $first, 'second_user_id' => $second], ['id' => $sourceId]);
        }

        $this->connection->executeStatement(<<<'SQL'
            UPDATE transfer
            SET connect_id = :destination,
                sender_id = CASE WHEN NOT hidden_by_merge AND sender_id = :joined THEN :primary ELSE sender_id END,
                receiver_id = CASE WHEN NOT hidden_by_merge AND receiver_id = :joined THEN :primary ELSE receiver_id END,
                created_by_id = CASE WHEN NOT hidden_by_merge AND created_by_id = :joined THEN :primary ELSE created_by_id END,
                version = version + 1
            WHERE connect_id = :source AND (connect_id <> :destination OR NOT hidden_by_merge)
            SQL, ['destination' => $destinationId, 'source' => $sourceId, 'primary' => $primary, 'joined' => $joined]);

        if ($destinationId !== $sourceId) {
            $this->connection->delete('connect', ['id' => $sourceId]);
        }
    }
}
