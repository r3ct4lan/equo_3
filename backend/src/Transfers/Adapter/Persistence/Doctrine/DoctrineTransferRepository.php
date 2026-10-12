<?php

declare(strict_types=1);

namespace App\Transfers\Adapter\Persistence\Doctrine;

use App\Transfers\Application\Port\TransferRepositoryPort;
use App\Transfers\Domain\Transfer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;

final readonly class DoctrineTransferRepository implements TransferRepositoryPort
{
    public function __construct(private Connection $db)
    {
    }

    public function balance(string $connectId, string $viewerId): int
    {
        $value = $this->db->fetchOne('SELECT COALESCE(SUM(CASE WHEN sender_id=:user THEN amount::bigint ELSE -amount::bigint END),0) FROM transfer WHERE connect_id=:connect AND is_deleted=false', ['user' => $viewerId, 'connect' => $connectId]);
        $amount = filter_var($value, FILTER_VALIDATE_INT);
        if (false === $amount) {
            throw new RuntimeException('Ledger balance exceeds the supported integer range.');
        }

        return $amount;
    }

    public function find(string $id): ?Transfer
    {
        $row = $this->db->fetchAssociative('SELECT * FROM transfer WHERE id=?', [$id]);

        return false === $row ? null : $this->map($row);
    }

    public function page(string $connectId, int $limit, ?DateTimeImmutable $before, ?string $beforeId): array
    {
        $sql = 'SELECT * FROM transfer WHERE connect_id=:connect';
        $params = ['connect' => $connectId];
        if (null !== $before) {
            $sql .= ' AND (created_at,id) < (:before,:id)';
            $params['before'] = $before->format('Y-m-d H:i:sP');
            $params['id'] = $beforeId;
        }
        $sql .= ' ORDER BY created_at DESC,id DESC LIMIT '.(int) $limit;

        return array_map($this->map(...), $this->db->fetchAllAssociative($sql, $params));
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): Transfer
    {
        return new Transfer((string) $row['id'], (string) $row['connect_id'], (string) $row['sender_id'], (string) $row['receiver_id'], (int) $row['amount'], (string) $row['type'], null === $row['created_by_id'] ? null : (string) $row['created_by_id'], new DateTimeImmutable((string) $row['created_at']), null === $row['debt_id'] ? null : (string) $row['debt_id'], null === $row['debt_participant_id'] ? null : (string) $row['debt_participant_id'], null === $row['related_transfer_id'] ? null : (string) $row['related_transfer_id'], (bool) $row['is_deleted'], (int) $row['version']);
    }

    public function save(Transfer $t): void
    {
        $this->db->insert('transfer', ['id' => $t->id, 'connect_id' => $t->connectId, 'sender_id' => $t->senderId, 'receiver_id' => $t->receiverId, 'amount' => $t->amount, 'type' => $t->type, 'created_by_id' => $t->createdById, 'created_at' => $t->createdAt->format('Y-m-d H:i:sP'), 'debt_id' => $t->debtId, 'debt_participant_id' => $t->debtParticipantId, 'related_transfer_id' => $t->relatedTransferId, 'is_deleted' => $t->isDeleted, 'version' => $t->version], ['is_deleted' => 'boolean']);
    }

    public function updateShare(Transfer $transfer): void
    {
        if ('DEBT_SHARE' !== $transfer->type) {
            throw new InvalidArgumentException('Only automatic shares can be persisted by recalculation.');
        }
        $this->db->update('transfer', ['amount' => $transfer->amount, 'is_deleted' => $transfer->isDeleted, 'version' => $transfer->version], ['id' => $transfer->id, 'type' => 'DEBT_SHARE'], ['is_deleted' => 'boolean']);
    }

    public function sharesForDebt(string $debtId): array
    {
        $result = [];
        foreach ($this->db->fetchAllAssociative("SELECT debt_participant_id, id FROM transfer WHERE debt_id=? AND type='DEBT_SHARE'", [$debtId]) as $row) {
            $result[(string) $row['debt_participant_id']] = (string) $row['id'];
        }

        return $result;
    }
}
