<?php

declare(strict_types=1);

namespace App\Transfers\Adapter\Persistence\Doctrine;

use App\Transfers\Application\Api\ReconcileShare;
use App\Transfers\Application\Port\TransferRepositoryPort;
use App\Transfers\Domain\Transfer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

final readonly class DoctrineTransferRepository implements TransferRepositoryPort
{
    // Automatic shares use the expense's current manual quote, including rate-only edits.
    private const string PROJECTION = 'SELECT t.*, CASE WHEN d.id IS NULL THEN t.manual_rate ELSE d.manual_rate END AS applied_manual_rate, CASE WHEN d.id IS NULL THEN t.rate_currency ELSE d.rate_currency END AS applied_rate_currency FROM transfer t LEFT JOIN debt d ON d.id=t.debt_id';

    public function __construct(private Connection $db, private ReconcileShare $settlements)
    {
    }

    public function openAmounts(string $viewerId, ?string $connectId): iterable
    {
        $sql = <<<'SQL'
            SELECT t.connect_id, t.sender_id, t.currency, t.type,
                   CASE WHEN t.type='DEBT_SHARE' THEN t.amount-t.credited_amount ELSE COALESCE(t.excess_amount, t.amount) END AS exposure,
                   CASE WHEN d.id IS NULL THEN CASE WHEN t.excess_amount IS NULL THEN t.manual_rate END ELSE d.manual_rate END AS manual_rate,
                   CASE WHEN d.id IS NULL THEN t.rate_currency ELSE d.rate_currency END AS rate_currency
            FROM transfer t LEFT JOIN debt d ON d.id=t.debt_id
            WHERE NOT t.is_deleted AND NOT t.hidden_by_merge AND (t.sender_id=:viewer OR t.receiver_id=:viewer)
            SQL;
        $parameters = ['viewer' => $viewerId];
        if (null !== $connectId) {
            $sql .= ' AND t.connect_id=:connect';
            $parameters['connect'] = $connectId;
        }
        foreach ($this->db->iterateAssociative($sql, $parameters) as $row) {
            yield ['isShare' => 'DEBT_SHARE' === $row['type'], 'connectId' => $row['connect_id'], 'senderId' => $row['sender_id'], 'amount' => (int) $row['exposure'], 'currency' => $row['currency'], 'manualRate' => $row['manual_rate'], 'rateCurrency' => $row['rate_currency']];
        }
    }

    public function shareBalances(string $debtId): array
    {
        $result = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, debt_participant_id, amount-credited_amount AS remaining FROM transfer WHERE debt_id=? AND NOT hidden_by_merge', [$debtId]) as $row) {
            $result[$row['debt_participant_id']] = ['id' => $row['id'], 'remaining' => (int) $row['remaining']];
        }

        return $result;
    }

    public function find(string $id, bool $forUpdate = false): ?Transfer
    {
        $row = $this->db->fetchAssociative(self::PROJECTION.' WHERE t.id=? AND NOT t.hidden_by_merge'.($forUpdate ? ' FOR UPDATE OF t' : ''), [$id]);

        return false === $row ? null : $this->map($row);
    }

    public function page(string $connectId, int $limit, ?DateTimeImmutable $before, ?string $beforeId): array
    {
        $sql = self::PROJECTION.' WHERE t.connect_id=:connect AND NOT t.hidden_by_merge';
        $params = ['connect' => $connectId];
        if (null !== $before) {
            $sql .= ' AND (t.created_at,t.id) < (:before,:id)';
            $params['before'] = $before->format('Y-m-d H:i:sP');
            $params['id'] = $beforeId;
        }
        $sql .= ' ORDER BY t.created_at DESC,t.id DESC LIMIT '.(int) $limit;

        return array_map($this->map(...), $this->db->fetchAllAssociative($sql, $params));
    }

    public function pageForUser(string $userId, string $direction, int $limit, ?DateTimeImmutable $before, ?string $beforeId): array
    {
        if (!in_array($direction, ['incoming', 'outgoing'], true)) {
            throw new InvalidArgumentException('Invalid transfer direction.');
        }
        $side = 'incoming' === $direction ? 'receiver_id' : 'sender_id';
        $sql = self::PROJECTION.' INNER JOIN connect c ON c.id=t.connect_id WHERE NOT t.hidden_by_merge AND t.'.$side.'=:viewer AND (c.first_user_id=:viewer OR c.second_user_id=:viewer)';
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
        return new Transfer((string) $row['id'], (string) $row['connect_id'], (string) $row['sender_id'], (string) $row['receiver_id'], (int) $row['amount'], (string) $row['type'], null === $row['created_by_id'] ? null : (string) $row['created_by_id'], new DateTimeImmutable((string) $row['created_at']), null === $row['debt_id'] ? null : (string) $row['debt_id'], null === $row['debt_participant_id'] ? null : (string) $row['debt_participant_id'], null === $row['related_transfer_id'] ? null : (string) $row['related_transfer_id'], (bool) $row['is_deleted'], (int) $row['version'], $row['currency'], $row['applied_manual_rate'], $row['applied_rate_currency'], $row['settlement_rate'], (int) $row['credited_amount'], null === $row['excess_amount'] ? null : (int) $row['excess_amount']);
    }

    public function save(Transfer $t): void
    {
        $this->db->insert('transfer', ['id' => $t->id, 'connect_id' => $t->connectId, 'sender_id' => $t->senderId, 'receiver_id' => $t->receiverId, 'amount' => $t->amount, 'type' => $t->type, 'created_by_id' => $t->createdById, 'created_at' => $t->createdAt->format('Y-m-d H:i:sP'), 'debt_id' => $t->debtId, 'debt_participant_id' => $t->debtParticipantId, 'currency' => $t->currency, 'manual_rate' => $t->manualRate, 'rate_currency' => $t->rateCurrency, 'settlement_rate' => $t->settlementRate, 'related_transfer_id' => $t->relatedTransferId, 'is_deleted' => $t->isDeleted, 'version' => $t->version], ['is_deleted' => 'boolean']);
        if (null !== $t->relatedTransferId) {
            $this->appendPayment($t->id, $t->relatedTransferId);
            $this->settlements->reconcile($t->relatedTransferId);
        }
    }

    public function updateShare(Transfer $transfer): void
    {
        if ('DEBT_SHARE' !== $transfer->type) {
            throw new InvalidArgumentException('Only automatic shares can be persisted by recalculation.');
        }
        $this->db->update('transfer', ['amount' => $transfer->amount, 'is_deleted' => $transfer->isDeleted, 'version' => $transfer->version], ['id' => $transfer->id, 'type' => 'DEBT_SHARE'], ['is_deleted' => 'boolean']);
        $this->settlements->reconcile($transfer->id);
    }

    public function updateManual(Transfer $transfer): void
    {
        if ('MANUAL' !== $transfer->type) {
            throw new InvalidArgumentException('Only manual transfers can be persisted by this command.');
        }
        $previous = $this->db->fetchOne('SELECT related_transfer_id FROM transfer WHERE id = ?', [$transfer->id]);
        $this->db->update('transfer', ['sender_id' => $transfer->senderId, 'receiver_id' => $transfer->receiverId, 'amount' => $transfer->amount, 'currency' => $transfer->currency, 'manual_rate' => $transfer->manualRate, 'rate_currency' => $transfer->rateCurrency, 'settlement_rate' => $transfer->settlementRate, 'credited_amount' => 0, 'excess_amount' => null, 'related_transfer_id' => $transfer->relatedTransferId, 'is_deleted' => $transfer->isDeleted, 'version' => $transfer->version], ['id' => $transfer->id, 'type' => 'MANUAL'], ['is_deleted' => 'boolean']);
        if (null !== $transfer->relatedTransferId && $previous !== $transfer->relatedTransferId) {
            $this->appendPayment($transfer->id, $transfer->relatedTransferId);
        }
        $targets = array_unique(array_filter([$previous, $transfer->relatedTransferId], 'is_string'));
        sort($targets, SORT_STRING);
        foreach ($targets as $target) {
            $this->settlements->reconcile($target);
        }
    }

    private function appendPayment(string $paymentId, string $shareId): void
    {
        // The command already holds the share lock. Changing an amount keeps its original position.
        $this->db->executeStatement('UPDATE transfer SET settlement_position=(SELECT COALESCE(MAX(settlement_position),0)+1 FROM transfer WHERE related_transfer_id=?) WHERE id=?', [$shareId, $paymentId]);
    }

    public function availableBefore(string $paymentId, string $shareId): int
    {
        return (int) $this->db->fetchOne(<<<'SQL'
            SELECT share.amount - COALESCE((
                SELECT SUM(p.credited_amount) FROM transfer p WHERE p.related_transfer_id=share.id AND NOT p.is_deleted AND NOT p.hidden_by_merge
                  AND (COALESCE(p.settlement_position,0),p.created_at,p.id) < (COALESCE(payment.settlement_position,0),payment.created_at,payment.id)
            ),0) FROM transfer share JOIN transfer payment ON payment.id=? WHERE share.id=?
            SQL, [$paymentId, $shareId]);
    }

    public function sharesForDebt(string $debtId): array
    {
        $result = [];
        foreach ($this->db->fetchAllAssociative("SELECT debt_participant_id, id FROM transfer WHERE debt_id=? AND type='DEBT_SHARE' AND NOT hidden_by_merge", [$debtId]) as $row) {
            $result[(string) $row['debt_participant_id']] = (string) $row['id'];
        }

        return $result;
    }
}
