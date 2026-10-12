<?php

declare(strict_types=1);

namespace App\Tests\Integration\Retention;

use App\Infrastructure\Retention\TechnicalDataCleanup;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class TechnicalDataCleanupTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testDryRunReportsEligibleCountsWithoutChangingRows(): void
    {
        $fixture = $this->fixture();
        $cleanup = self::getContainer()->get(TechnicalDataCleanup::class);

        $counts = $cleanup->run(10, true);

        self::assertSame(1, $counts['terminal_payloads_cleared']);
        self::assertSame(1, $counts['email_outbox']);
        self::assertSame(1, $counts['idempotency']);
        self::assertSame(1, $counts['sessions']);
        self::assertSame(1, $counts['invitations']);
        self::assertSame(0, $counts['action_tokens']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM email_delivery_outbox WHERE id = ?', [$fixture['outbox']]));
    }

    public function testCleanupDeletesExpiredRecordsInForeignKeyOrderAndKeepsActiveRecords(): void
    {
        $fixture = $this->fixture();
        $cleanup = self::getContainer()->get(TechnicalDataCleanup::class);

        $counts = $cleanup->run(10);

        self::assertSame(1, $counts['terminal_payloads_cleared']);
        self::assertSame(1, $counts['email_outbox']);
        self::assertSame(1, $counts['idempotency']);
        self::assertSame(1, $counts['sessions']);
        self::assertSame(1, $counts['invitations']);
        self::assertSame(1, $counts['action_tokens']);
        foreach (['outbox' => 'email_delivery_outbox', 'idempotency' => 'idempotency_record', 'session' => 'user_session', 'invitation' => 'connect_invitation', 'token' => 'user_action_token'] as $key => $table) {
            self::assertSame(0, (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE id = ?', $table), [$fixture[$key]]));
        }

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE id = ?', [$fixture['active_token']]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM email_delivery_outbox WHERE id = ?', [$fixture['active_outbox']]));
    }

    public function testCleanupIsBoundedAndRepeatable(): void
    {
        $this->fixture(extraIdempotency: true);
        $cleanup = self::getContainer()->get(TechnicalDataCleanup::class);

        $first = $cleanup->run(1);
        $second = $cleanup->run(1);

        self::assertSame(1, $first['idempotency']);
        self::assertSame(1, $second['idempotency']);
        self::assertSame(0, $cleanup->run(1)['idempotency']);
    }

    public function testExpiredDeliverableIsTerminalizedBeforeItsTokenCanBeDeleted(): void
    {
        $this->fixture();
        $userId = Uuid::v4()->toRfc4122();
        $tokenId = Uuid::v4()->toRfc4122();
        $outboxId = Uuid::v4()->toRfc4122();
        $this->connection->insert('app_user', [
            'id' => $userId,
            'name' => 'Expired delivery',
            'email' => $userId.'@example.test',
            'password_hash' => 'unused-test-hash',
            'is_active' => 'false',
            'created_at' => '2026-01-01T00:00:00+00:00',
        ]);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO user_action_token (id, user_id, token_hash, purpose, payload, created_at, expires_at)
            VALUES (:id, :user_id, :hash, 'ACTIVATE_ACCOUNT', NULL,
                    CURRENT_TIMESTAMP - INTERVAL '60 days', CURRENT_TIMESTAMP - INTERVAL '59 days')
            SQL, ['id' => $tokenId, 'user_id' => $userId, 'hash' => 'pending-'.$tokenId]);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO email_delivery_outbox (
                id, user_action_token_id, recipient_email, template_key, encrypted_payload, status,
                created_at, available_at, published_at, sent_at, failed_at, publish_attempts, last_error
            ) VALUES (:id, :token, 'expired@example.test', 'ACTIVATE_ACCOUNT', decode('0102', 'hex'), 'PENDING',
                      CURRENT_TIMESTAMP - INTERVAL '60 days', CURRENT_TIMESTAMP - INTERVAL '60 days',
                      NULL, NULL, NULL, 1, NULL)
            SQL, ['id' => $outboxId, 'token' => $tokenId]);

        $counts = self::getContainer()->get(TechnicalDataCleanup::class)->run(10);

        self::assertSame(1, $counts['expired_deliveries']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE id = ?', [$tokenId]));
        self::assertSame('FAILED', $this->connection->fetchOne('SELECT status FROM email_delivery_outbox WHERE id = ?', [$outboxId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM email_delivery_outbox WHERE id = ? AND encrypted_payload IS NOT NULL', [$outboxId]));
    }

    /** @return array<string, string> */
    private function fixture(bool $extraIdempotency = false): array
    {
        $user = Uuid::v4()->toRfc4122();
        $otherUser = Uuid::v4()->toRfc4122();
        $token = Uuid::v4()->toRfc4122();
        $outbox = Uuid::v4()->toRfc4122();
        $session = Uuid::v4()->toRfc4122();
        $invitation = Uuid::v4()->toRfc4122();
        $idempotency = Uuid::v4()->toRfc4122();
        $activeToken = Uuid::v4()->toRfc4122();
        $activeOutbox = Uuid::v4()->toRfc4122();

        foreach ([$user, $otherUser] as $userId) {
            $this->connection->insert('app_user', [
                'id' => $userId,
                'name' => 'Retention test',
                'email' => $userId.'@example.test',
                'password_hash' => 'unused-test-hash',
                'is_active' => true,
                'created_at' => '2026-01-01T00:00:00+00:00',
            ]);
        }

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO user_action_token (id, user_id, token_hash, purpose, payload, created_at, expires_at, used_at)
            VALUES (:id, :user_id, :hash, 'ACTIVATE_ACCOUNT', NULL,
                    CURRENT_TIMESTAMP - INTERVAL '60 days', CURRENT_TIMESTAMP - INTERVAL '59 days',
                    CURRENT_TIMESTAMP - INTERVAL '40 days')
            SQL, ['id' => $token, 'user_id' => $user, 'hash' => 'old-'.$token]);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO email_delivery_outbox (
                id, user_action_token_id, recipient_email, template_key, encrypted_payload, status,
                created_at, available_at, published_at, sent_at, failed_at, publish_attempts, last_error
            ) VALUES (:id, :token, 'retention@example.test', 'ACTIVATE_ACCOUNT', decode('0102', 'hex'), 'SENT',
                      CURRENT_TIMESTAMP - INTERVAL '40 days', CURRENT_TIMESTAMP - INTERVAL '40 days',
                      CURRENT_TIMESTAMP - INTERVAL '40 days', CURRENT_TIMESTAMP - INTERVAL '40 days', NULL, 1, NULL)
            SQL, ['id' => $outbox, 'token' => $token]);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO user_session (id, user_id, refresh_token_hash, created_at, expires_at, revoked_at)
            VALUES (:id, :user_id, :hash, CURRENT_TIMESTAMP - INTERVAL '120 days',
                    CURRENT_TIMESTAMP - INTERVAL '90 days', NULL)
            SQL, ['id' => $session, 'user_id' => $user, 'hash' => 'old-session-'.$session]);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO connect_invitation (id, created_by_id, token_hash, created_at, expires_at, used_at, accepted_by_id)
            VALUES (:id, :creator, :hash, CURRENT_TIMESTAMP - INTERVAL '120 days',
                    CURRENT_TIMESTAMP - INTERVAL '113 days', CURRENT_TIMESTAMP - INTERVAL '40 days', :acceptor)
            SQL, ['id' => $invitation, 'creator' => $user, 'hash' => 'old-invitation-'.$invitation, 'acceptor' => $otherUser]);
        $this->insertIdempotency($idempotency, $user);

        if ($extraIdempotency) {
            $this->insertIdempotency(Uuid::v4()->toRfc4122(), $user);
        }

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO user_action_token (id, user_id, token_hash, purpose, payload, created_at, expires_at)
            VALUES (:id, :user_id, :hash, 'RESET_PASSWORD', NULL,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP + INTERVAL '30 minutes')
            SQL, ['id' => $activeToken, 'user_id' => $otherUser, 'hash' => 'active-'.$activeToken]);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO email_delivery_outbox (
                id, user_action_token_id, recipient_email, template_key, encrypted_payload, status,
                created_at, available_at, published_at, sent_at, failed_at, publish_attempts, last_error
            ) VALUES (:id, :token, 'active@example.test', 'RESET_PASSWORD', decode('0102', 'hex'), 'PENDING',
                      CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL, NULL, NULL, 0, NULL)
            SQL, ['id' => $activeOutbox, 'token' => $activeToken]);

        return [
            'outbox' => $outbox,
            'idempotency' => $idempotency,
            'session' => $session,
            'invitation' => $invitation,
            'token' => $token,
            'active_token' => $activeToken,
            'active_outbox' => $activeOutbox,
        ];
    }

    private function insertIdempotency(string $id, string $userId): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO idempotency_record (
                id, scope, user_id, operation, idempotency_key, request_hash,
                response_status, response_body, created_at, expires_at
            ) VALUES (:id, :scope, :user_id, 'test.operation', :key, 'test-hash', 200, '{}',
                      CURRENT_TIMESTAMP - INTERVAL '2 days', CURRENT_TIMESTAMP - INTERVAL '1 day')
            SQL, ['id' => $id, 'scope' => 'user:'.$userId, 'user_id' => $userId, 'key' => 'test-'.$id]);
    }
}
