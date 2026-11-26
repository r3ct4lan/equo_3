<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Persistence\Doctrine;

use App\Debts\Application\Port\MergeExpenseRepositoryPort;
use App\Debts\Domain\Debt;
use App\Transfers\Application\Api\ReconcileShare;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

final readonly class DoctrineMergeExpenseRepository implements MergeExpenseRepositoryPort
{
    public function __construct(private Connection $connection, private ReconcileShare $settlements)
    {
    }

    public function expenseIdsFor(string $joined): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT d.id
            FROM debt d
            WHERE NOT d.hidden_by_merge
              AND (d.payer_id = :joined OR d.created_by_id = :joined OR EXISTS (
                  SELECT 1 FROM debt_participant p WHERE p.debt_id = d.id AND p.user_id = :joined
              ))
            ORDER BY d.id
            SQL, ['joined' => $joined]));
    }

    public function hide(Debt $expense): void
    {
        $this->connection->executeStatement(
            'UPDATE debt SET hidden_by_merge = true, is_deleted = true, version = version + 1 WHERE id = ?',
            [$expense->id],
        );
        $this->connection->executeStatement(
            'UPDATE transfer SET hidden_by_merge = true, is_deleted = true, version = version + 1 WHERE debt_id = ? AND NOT hidden_by_merge',
            [$expense->id],
        );
    }

    public function replace(Debt $previous, Debt $merged, string $primary, string $joined): void
    {
        $value = $this->connection->fetchOne('SELECT allocation FROM receipt_upload WHERE debt_id=?', [$merged->id]);
        if (is_string($value)) {
            $receipt = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            foreach ($receipt['lines'] as &$line) {
                [$line['assignments'], $seats] = self::mergeAssignments($line['assignments'], $line['mergedSeats'] ?? [], $primary, $joined);
                if ([] !== $seats) {
                    $line['mergedSeats'] = $seats;
                }
            }
            unset($line);
            [$receipt['remainder'], $seats] = self::mergeAssignments($receipt['remainder'], $receipt['remainderSeats'] ?? [], $primary, $joined);
            if ([] !== $seats) {
                $receipt['remainderSeats'] = $seats;
            }
            $this->connection->update('receipt_upload', ['allocation' => json_encode($receipt, JSON_THROW_ON_ERROR)], ['debt_id' => $merged->id]);
        }
        $this->connection->update('debt', ['payer_id' => $merged->payerId, 'created_by_id' => $merged->createdById, 'version' => $merged->version], ['id' => $merged->id]);
        $joinedParticipantId = array_column($previous->participants, 'id', 'userId')[$joined] ?? null;
        $shares = array_column($this->connection->fetchAllAssociative(
            "SELECT id, debt_participant_id, amount, is_deleted FROM transfer WHERE debt_id = ? AND type = 'DEBT_SHARE' AND NOT hidden_by_merge",
            [$merged->id],
        ), null, 'debt_participant_id');
        $donor = null === $joinedParticipantId ? null : ($shares[$joinedParticipantId] ?? null);

        foreach ($merged->participants as $participant) {
            $this->connection->update('debt_participant', [
                'user_id' => $participant->userId,
                'is_deleted' => $participant->isDeleted,
                'actual_share_amount' => $participant->actualShareAmount,
            ], ['id' => $participant->id], ['is_deleted' => 'boolean']);

            if ($participant->userId === $joined) {
                continue;
            }
            $share = $shares[$participant->id] ?? null;
            $combined = $participant->userId === $primary && $joinedParticipantId !== $participant->id ? $donor : null;
            if ($participant->userId === $merged->payerId) {
                if (null !== $share) {
                    $this->hideShare((string) $share['id']);
                }
                if (null !== $combined) {
                    $this->hideShare((string) $combined['id']);
                }
                continue;
            }

            $kept = $share ?? $combined;
            if (null === $kept) {
                continue;
            }
            $amount = $participant->actualShareAmount ?? (int) $kept['amount'];
            $deleted = $merged->isDeleted || $participant->isDeleted || 0 === $amount;
            if (0 === $amount) {
                $amount = (int) $kept['amount'];
            }
            if ((int) $kept['amount'] !== $amount || (bool) $kept['is_deleted'] !== $deleted || $kept['debt_participant_id'] !== $participant->id) {
                $this->connection->executeStatement(
                    'UPDATE transfer SET amount = ?, is_deleted = ?, debt_participant_id = ?, version = version + 1 WHERE id = ?',
                    [$amount, $deleted, $participant->id, (string) $kept['id']],
                    [1 => 'boolean'],
                );
            }
            if (null !== $share && null !== $combined) {
                $this->hideShare((string) $combined['id']);
                $this->connection->executeStatement(
                    'UPDATE transfer SET related_transfer_id = ?, version = version + 1 WHERE related_transfer_id = ?',
                    [(string) $share['id'], (string) $combined['id']],
                );
            }
            $this->settlements->reconcile((string) $kept['id']);
        }
    }

    /**
     * @param array<string,?string> $assigned
     * @param array<string,int>     $seats
     *
     * @return array{array<string,?string>,array<string,int>}
     */
    private static function mergeAssignments(array $assigned, array $seats, string $primary, string $joined): array
    {
        if (!array_key_exists($joined, $assigned)) {
            return [$assigned, $seats];
        }
        $quantity = $assigned[$joined];
        if (null === $quantity && (array_key_exists($primary, $assigned) || [] !== $seats)) {
            $seats[$primary] = ($seats[$primary] ?? (array_key_exists($primary, $assigned) ? 1 : 0)) + ($seats[$joined] ?? 1);
        }
        $assigned[$primary] = null === $quantity ? null : BigDecimal::of($assigned[$primary] ?? 0)->plus($quantity)->toString();
        unset($assigned[$joined], $seats[$joined]);

        return [$assigned, $seats];
    }

    private function hideShare(string $id): void
    {
        $this->connection->executeStatement(
            'UPDATE transfer SET hidden_by_merge = true, is_deleted = true, version = version + 1 WHERE id = ?',
            [$id],
        );
        $this->settlements->reconcile($id);
    }
}
