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

    public function balanceSummary(string $viewerId): array
    {
        $row = $this->db->fetchAssociative(<<<'SQL'
            SELECT COALESCE(SUM(CASE WHEN balance < 0 THEN -balance ELSE 0 END), 0) AS owed,
                   COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS receivable
            FROM (
                SELECT connect_id, SUM(CASE WHEN sender_id = :viewer THEN amount::bigint ELSE -amount::bigint END) AS balance
                FROM transfer
                WHERE is_deleted = false AND (sender_id = :viewer OR receiver_id = :viewer)
                GROUP BY connect_id
            ) AS balances
            SQL, ['viewer' => $viewerId]);
        if (false === $row) {
            throw new RuntimeException('Ledger summary is unavailable.');
        }

        return [
            'totalOwedAmount' => $this->exactSummaryAmount($row['owed']),
            'totalReceivableAmount' => $this->exactSummaryAmount($row['receivable']),
        ];
    }

    private function exactSummaryAmount(mixed $value): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT);
        if (false === $amount || $amount < 0 || $amount > 9007199254740991) {
            throw new RuntimeException('Ledger summary exceeds the supported exact integer range.');
        }

        return $amount;
    }

    public function find(string $id, bool $forUpdate = false): ?Transfer
    {
        $row = $this->db->fetchAssociative('SELECT * FROM transfer WHERE id=?'.($forUpdate ? ' FOR UPDATE' : ''), [$id]);

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

    public function pageForUser(string $userId, string $direction, int $limit, ?DateTimeImmutable $before, ?string $beforeId): array
    {
        if (!in_array($direction, ['incoming', 'outgoing'], true)) {
            throw new InvalidArgumentException('Invalid transfer direction.');
        }
        $side = 'incoming' === $direction ? 'receiver_id' : 'sender_id';
        $sql = 'SELECT t.* FROM transfer t INNER JOIN connect c ON c.id=t.connect_id WHERE t.'.$side.'=:viewer AND (c.first_user_id=:viewer OR c.second_user_id=:viewer)';
        $params = ['viewer' => $userId];
        if (null !== $before) {
            $sql .= ' AND (t.created_at,t.id) < (:before,:id)';
            $params['before'] = $before->format('Y-m-d H:i:sP');
            $params['id'] = $beforeId;
        }
        $sql .= ' ORDER BY t.created_at DESC,t.id DESC LIMIT '.(int) $limit;

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

    public function updateManual(Transfer $transfer): void
    {
        if ('MANUAL' !== $transfer->type) {
            throw new InvalidArgumentException('Only manual transfers can be persisted by this command.');
        }
        $this->db->update('transfer', ['sender_id' => $transfer->senderId, 'receiver_id' => $transfer->receiverId, 'amount' => $transfer->amount, 'related_transfer_id' => $transfer->relatedTransferId, 'is_deleted' => $transfer->isDeleted, 'version' => $transfer->version], ['id' => $transfer->id, 'type' => 'MANUAL'], ['is_deleted' => 'boolean']);
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
