<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Credentials\AccountMergePreview;
use App\IdentityAccess\Application\Port\AccountMergeExecutionPort;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use LogicException;

final readonly class DoctrineAccountMergeExecution implements AccountMergeExecutionPort
{
    public function __construct(private Connection $connection)
    {
    }

    public function completed(string $userId, string $sessionId, string $version): bool
    {
        return false !== $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM user_action_token
            WHERE user_id = :user AND purpose = 'MERGE_ACCOUNT' AND used_at IS NOT NULL
              AND expires_at > CURRENT_TIMESTAMP
              AND payload->>'sessionId' = :session AND payload->>'confirmedVersion' = :version
            LIMIT 1
            SQL, ['user' => $userId, 'session' => $sessionId, 'version' => $version]);
    }

    public function lockFootprint(string $primary, string $joined): void
    {
        try {
            // Regular finance commands lock different aggregate roots before inspecting users.
            // Refuse contention instead of waiting on those locks while holding an account row.
            $this->connection->executeStatement('LOCK TABLE connect, connect_invitation, debt, debt_participant, transfer IN EXCLUSIVE MODE NOWAIT');
            $accounts = $this->connection->fetchFirstColumn(
                'SELECT id FROM app_user WHERE id IN (?, ?) ORDER BY id FOR UPDATE NOWAIT',
                [$primary, $joined],
            );
        } catch (DriverException $failure) {
            if ('55P03' === $failure->getSQLState()) {
                throw new ApplicationFailure(ApplicationFailureCode::MergeBusy);
            }

            throw $failure;
        }
        if (2 !== count($accounts)) {
            throw new ApplicationFailure(ApplicationFailureCode::CredentialConflict);
        }
    }

    public function lockTelegramIdentities(AccountMergePreview $preview): void
    {
        $identities = array_filter([$preview->snapshot->primary['telegramId'], $preview->snapshot->joined['telegramId']]);
        sort($identities, SORT_NUMERIC);
        foreach ($identities as $id) {
            $locked = $this->connection->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0))',
                ['telegram-identity:'.$id],
            );
            if (true !== $locked) {
                throw new ApplicationFailure(ApplicationFailureCode::MergeBusy);
            }
        }
    }

    public function complete(string $primary, AccountMergePreview $preview, DateTimeImmutable $now): void
    {
        $time = $now->format('Y-m-d H:i:s.uP');
        $pending = $this->connection->fetchOne(<<<'SQL'
            SELECT invite.id FROM app_user u INNER JOIN connect_invitation invite ON invite.id = u.pending_invitation_id
            WHERE u.id IN (:primary, :joined) AND invite.created_by_id NOT IN (:primary, :joined)
              AND invite.used_at IS NULL AND invite.expires_at > :now
            ORDER BY (u.id = :primary) DESC
            LIMIT 1
            SQL, ['primary' => $primary, 'joined' => $preview->targetId, 'now' => $time]);
        $changes = ['telegram_id' => $preview->telegramId, 'pending_invitation_id' => is_string($pending) ? $pending : null];
        if ('link_email' === $preview->mode) {
            $passwordHash = $this->connection->fetchOne('SELECT password_hash FROM app_user WHERE id = ?', [$preview->targetId]);
            if (!is_string($passwordHash)) {
                throw new ApplicationFailure(ApplicationFailureCode::CredentialConflict);
            }
            $changes['email'] = $preview->email;
            $changes['password_hash'] = $passwordHash;
        }

        $this->connection->executeStatement(<<<'SQL'
            UPDATE app_user
            SET email = NULL, password_hash = NULL, telegram_id = NULL, pending_invitation_id = NULL,
                is_active = false, merged_into_id = :primary
            WHERE id = :joined
            SQL, ['primary' => $primary, 'joined' => $preview->targetId]);
        $this->connection->update('app_user', $changes, ['id' => $primary]);

        $this->connection->executeStatement(<<<'SQL'
            UPDATE user_action_token SET invalidated_at = :now
            WHERE user_id = :joined AND used_at IS NULL AND invalidated_at IS NULL
            SQL, ['now' => $time, 'joined' => $preview->targetId]);
        $used = $this->connection->executeStatement(<<<'SQL'
            UPDATE user_action_token
            SET used_at = :now, payload = jsonb_set(payload, '{confirmedVersion}', to_jsonb(CAST(:version AS text)))
            WHERE id = :id AND used_at IS NULL AND invalidated_at IS NULL
            SQL, ['now' => $time, 'version' => $preview->version, 'id' => $preview->proof->id]);
        if (1 !== $used) {
            throw new LogicException('Merge confirmation is no longer pending.');
        }
    }
}
