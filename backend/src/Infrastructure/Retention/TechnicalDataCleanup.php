<?php

declare(strict_types=1);

namespace App\Infrastructure\Retention;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

final readonly class TechnicalDataCleanup
{
    private const int LOCK_NAMESPACE = 67113822;
    private const int LOCK_ID = 22;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<string, int>
     */
    public function run(int $batchSize, bool $dryRun = false): array
    {
        if ($batchSize < 1 || $batchSize > 5000) {
            throw new InvalidArgumentException('Batch size must be between 1 and 5000.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($batchSize, $dryRun): array {
            if (!$dryRun && !$connection->fetchOne(
                'SELECT pg_try_advisory_xact_lock(:namespace, :lock_id)',
                ['namespace' => self::LOCK_NAMESPACE, 'lock_id' => self::LOCK_ID],
            )) {
                return ['skipped' => 1];
            }

            $counts = [];
            if ($dryRun) {
                return $this->countEligible($connection);
            }

            $counts['terminal_payloads_cleared'] = (int) $connection->executeStatement(<<<'SQL'
                WITH candidates AS (
                    SELECT id
                    FROM email_delivery_outbox
                    WHERE status IN ('SENT', 'FAILED') AND encrypted_payload IS NOT NULL
                    ORDER BY COALESCE(sent_at, failed_at), id
                    LIMIT :batch_size
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE email_delivery_outbox AS outbox
                SET encrypted_payload = NULL
                FROM candidates
                WHERE outbox.id = candidates.id
                SQL, ['batch_size' => $batchSize]);

            $counts['expired_deliveries'] = (int) $connection->executeStatement(<<<'SQL'
                WITH candidates AS (
                    SELECT outbox.id
                    FROM email_delivery_outbox AS outbox
                    INNER JOIN user_action_token AS token ON token.id = outbox.user_action_token_id
                    WHERE outbox.status IN ('PENDING', 'PUBLISHED')
                      AND token.expires_at <= CURRENT_TIMESTAMP
                    ORDER BY token.expires_at, outbox.created_at, outbox.id
                    LIMIT :batch_size
                    FOR UPDATE OF outbox SKIP LOCKED
                )
                UPDATE email_delivery_outbox AS outbox
                SET status = 'FAILED',
                    failed_at = CURRENT_TIMESTAMP,
                    encrypted_payload = NULL,
                    last_error = 'Action token expired before delivery.'
                FROM candidates
                WHERE outbox.id = candidates.id
                SQL, ['batch_size' => $batchSize]);

            $counts['email_outbox'] = $this->deleteBatch($connection, 'email_delivery_outbox', <<<'SQL'
                SELECT id FROM email_delivery_outbox
                WHERE status IN ('SENT', 'FAILED')
                  AND COALESCE(sent_at, failed_at) <= CURRENT_TIMESTAMP - INTERVAL '30 days'
                ORDER BY COALESCE(sent_at, failed_at), id
                SQL, $batchSize);
            $counts['idempotency'] = $this->deleteBatch($connection, 'idempotency_record', <<<'SQL'
                SELECT id FROM idempotency_record
                WHERE expires_at <= CURRENT_TIMESTAMP
                ORDER BY expires_at, id
                SQL, $batchSize);
            $counts['sessions'] = $this->deleteBatch($connection, 'user_session', <<<'SQL'
                SELECT id FROM user_session
                WHERE COALESCE(revoked_at, expires_at) <= CURRENT_TIMESTAMP - INTERVAL '30 days'
                ORDER BY COALESCE(revoked_at, expires_at), id
                SQL, $batchSize);
            $counts['invitations'] = $this->deleteBatch($connection, 'connect_invitation', <<<'SQL'
                SELECT id FROM connect_invitation
                WHERE COALESCE(used_at, expires_at) <= CURRENT_TIMESTAMP - INTERVAL '30 days'
                ORDER BY COALESCE(used_at, expires_at), id
                SQL, $batchSize);
            $counts['action_tokens'] = $this->deleteBatch($connection, 'user_action_token', <<<'SQL'
                SELECT id FROM user_action_token
                WHERE (
                    (used_at IS NOT NULL AND used_at <= CURRENT_TIMESTAMP - INTERVAL '30 days')
                    OR (invalidated_at IS NOT NULL AND invalidated_at <= CURRENT_TIMESTAMP - INTERVAL '30 days')
                    OR (used_at IS NULL AND invalidated_at IS NULL
                        AND expires_at <= CURRENT_TIMESTAMP - INTERVAL '30 days')
                )
                  AND NOT EXISTS (
                      SELECT 1 FROM email_delivery_outbox AS outbox
                      WHERE outbox.user_action_token_id = user_action_token.id
                        AND (outbox.status NOT IN ('SENT', 'FAILED')
                             OR COALESCE(outbox.sent_at, outbox.failed_at) > CURRENT_TIMESTAMP - INTERVAL '30 days')
                  )
                ORDER BY COALESCE(used_at, invalidated_at, expires_at), id
                SQL, $batchSize);

            return $counts;
        });
    }

    /** @return array<string, int> */
    private function countEligible(Connection $connection): array
    {
        return [
            'terminal_payloads_cleared' => (int) $connection->fetchOne(<<<'SQL'
                SELECT COUNT(*) FROM email_delivery_outbox
                WHERE status IN ('SENT', 'FAILED') AND encrypted_payload IS NOT NULL
                SQL),
            'expired_deliveries' => (int) $connection->fetchOne(<<<'SQL'
                SELECT COUNT(*) FROM email_delivery_outbox AS outbox
                INNER JOIN user_action_token AS token ON token.id = outbox.user_action_token_id
                WHERE outbox.status IN ('PENDING', 'PUBLISHED') AND token.expires_at <= CURRENT_TIMESTAMP
                SQL),
            'email_outbox' => (int) $connection->fetchOne(<<<'SQL'
                SELECT COUNT(*) FROM email_delivery_outbox
                WHERE status IN ('SENT', 'FAILED')
                  AND COALESCE(sent_at, failed_at) <= CURRENT_TIMESTAMP - INTERVAL '30 days'
                SQL),
            'idempotency' => (int) $connection->fetchOne('SELECT COUNT(*) FROM idempotency_record WHERE expires_at <= CURRENT_TIMESTAMP'),
            'sessions' => (int) $connection->fetchOne(<<<'SQL'
                SELECT COUNT(*) FROM user_session
                WHERE COALESCE(revoked_at, expires_at) <= CURRENT_TIMESTAMP - INTERVAL '30 days'
                SQL),
            'invitations' => (int) $connection->fetchOne(<<<'SQL'
                SELECT COUNT(*) FROM connect_invitation
                WHERE COALESCE(used_at, expires_at) <= CURRENT_TIMESTAMP - INTERVAL '30 days'
                SQL),
            'action_tokens' => (int) $connection->fetchOne(<<<'SQL'
                SELECT COUNT(*) FROM user_action_token
                WHERE (
                    (used_at IS NOT NULL AND used_at <= CURRENT_TIMESTAMP - INTERVAL '30 days')
                    OR (invalidated_at IS NOT NULL AND invalidated_at <= CURRENT_TIMESTAMP - INTERVAL '30 days')
                    OR (used_at IS NULL AND invalidated_at IS NULL
                        AND expires_at <= CURRENT_TIMESTAMP - INTERVAL '30 days')
                )
                  AND NOT EXISTS (
                      SELECT 1 FROM email_delivery_outbox AS outbox
                      WHERE outbox.user_action_token_id = user_action_token.id
                  )
                SQL),
        ];
    }

    private function deleteBatch(Connection $connection, string $table, string $orderedIdsQuery, int $batchSize): int
    {
        return (int) $connection->executeStatement(
            sprintf(
                "WITH candidates AS (%s LIMIT :batch_size FOR UPDATE SKIP LOCKED)\nDELETE FROM %s AS target USING candidates WHERE target.id = candidates.id",
                $orderedIdsQuery,
                $table,
            ),
            ['batch_size' => $batchSize],
        );
    }
}
