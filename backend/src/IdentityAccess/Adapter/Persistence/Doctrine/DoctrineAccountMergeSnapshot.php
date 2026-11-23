<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Application\Credentials\AccountMergeSnapshot;
use App\IdentityAccess\Application\Port\AccountMergeSnapshotPort;
use Doctrine\DBAL\Connection;

/**
 * @phpstan-import-type MergeAccount from AccountMergeSnapshot
 * @phpstan-import-type MergeTransfer from AccountMergeSnapshot
 * @phpstan-import-type MergeExpense from AccountMergeSnapshot
 */
final readonly class DoctrineAccountMergeSnapshot implements AccountMergeSnapshotPort
{
    public function __construct(private Connection $connection)
    {
    }

    public function snapshot(string $primary, string $joined): ?AccountMergeSnapshot
    {
        $result = $this->connection->fetchOne(<<<'SQL'
            WITH pair AS (
                SELECT CAST(:primary AS uuid) AS primary_id, CAST(:joined AS uuid) AS joined_id
            ),
            affected_debts AS (
                SELECT debt.*
                FROM debt CROSS JOIN pair
                WHERE NOT debt.hidden_by_merge AND (
                       debt.payer_id IN (pair.primary_id, pair.joined_id)
                   OR debt.created_by_id IN (pair.primary_id, pair.joined_id)
                   OR EXISTS (
                       SELECT 1 FROM debt_participant
                       WHERE debt_id = debt.id AND user_id IN (pair.primary_id, pair.joined_id)
                   )
                )
            ),
            internal_expenses AS (
                SELECT debt.id, debt.created_at, debt.total_amount, debt.currency, debt.title
                FROM affected_debts debt CROSS JOIN pair
                WHERE debt.payer_id IN (pair.primary_id, pair.joined_id)
                  AND NOT EXISTS (
                      SELECT 1 FROM debt_participant
                      WHERE debt_id = debt.id AND NOT is_deleted AND user_id NOT IN (pair.primary_id, pair.joined_id)
                  )
            ),
            affected_transfers AS (
                SELECT transfer.*
                FROM transfer CROSS JOIN pair
                WHERE NOT transfer.hidden_by_merge AND (
                       transfer.sender_id IN (pair.primary_id, pair.joined_id)
                   OR transfer.receiver_id IN (pair.primary_id, pair.joined_id)
                   OR transfer.created_by_id IN (pair.primary_id, pair.joined_id)
                   OR transfer.debt_id IN (SELECT id FROM affected_debts)
                )
            ),
            internal_transfers AS (
                SELECT transfer.id, transfer.created_at, transfer.amount, transfer.currency
                FROM affected_transfers transfer CROSS JOIN pair
                WHERE transfer.type = 'MANUAL'
                  AND transfer.sender_id IN (pair.primary_id, pair.joined_id)
                  AND transfer.receiver_id IN (pair.primary_id, pair.joined_id)
            ),
            finance_fingerprint AS (
                SELECT jsonb_build_array(
                    (SELECT jsonb_agg(to_jsonb(debt) ORDER BY debt.id) FROM affected_debts debt),
                    (SELECT jsonb_agg(to_jsonb(participant) ORDER BY participant.id) FROM debt_participant participant
                      WHERE participant.debt_id IN (SELECT id FROM affected_debts)),
                    (SELECT jsonb_agg(to_jsonb(transfer) ORDER BY transfer.id) FROM affected_transfers transfer),
                    (SELECT jsonb_agg(to_jsonb(connect) ORDER BY connect.id) FROM connect CROSS JOIN pair
                      WHERE NOT connect.hidden_by_merge AND (connect.first_user_id IN (pair.primary_id, pair.joined_id)
                         OR connect.second_user_id IN (pair.primary_id, pair.joined_id))),
                    (SELECT jsonb_agg(jsonb_build_array(invite.id, invite.created_by_id, invite.accepted_by_id, invite.expires_at, invite.used_at) ORDER BY invite.id)
                     FROM connect_invitation invite CROSS JOIN pair
                     WHERE invite.created_by_id IN (pair.primary_id, pair.joined_id)
                        OR invite.accepted_by_id IN (pair.primary_id, pair.joined_id))
                ) AS elements
            )
            SELECT jsonb_build_object(
                'primary', jsonb_build_object('email', primary_user.email, 'telegramId', primary_user.telegram_id, 'active', primary_user.is_active),
                'joined', jsonb_build_object('email', joined_user.email, 'telegramId', joined_user.telegram_id, 'active', joined_user.is_active),
                'financeVersion', encode(sha256(convert_to(finance_fingerprint.elements::text, 'UTF8')), 'hex'),
                'transfers', COALESCE(
                    (SELECT jsonb_agg(jsonb_build_object('id', id, 'currency', currency, 'amount', amount,
                        'createdAt', to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')) ORDER BY created_at DESC, id)
                     FROM internal_transfers), '[]'::jsonb),
                'expenses', COALESCE(
                    (SELECT jsonb_agg(jsonb_build_object('id', id, 'currency', currency, 'amount', total_amount, 'title', title,
                        'createdAt', to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')) ORDER BY created_at DESC, id)
                     FROM internal_expenses), '[]'::jsonb)
            )::text
            FROM pair CROSS JOIN finance_fingerprint
            JOIN app_user primary_user ON primary_user.id = pair.primary_id
            JOIN app_user joined_user ON joined_user.id = pair.joined_id
            SQL, ['primary' => $primary, 'joined' => $joined]);
        if (!is_string($result)) {
            return null;
        }

        /** @var array{primary: MergeAccount, joined: MergeAccount, transfers: list<MergeTransfer>, expenses: list<MergeExpense>, financeVersion: string} $data */
        $data = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

        return new AccountMergeSnapshot($data['primary'], $data['joined'], $data['transfers'], $data['expenses'], $data['financeVersion']);
    }
}
