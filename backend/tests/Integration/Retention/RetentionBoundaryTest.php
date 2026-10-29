<?php

declare(strict_types=1);

namespace App\Tests\Integration\Retention;

use App\Infrastructure\Retention\TechnicalDataCleanup;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RetentionBoundaryTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        // PostgreSQL CURRENT_TIMESTAMP is fixed throughout this transaction.
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[DataProvider('terminalRecords')]
    public function testThirtyDayBoundaryForEveryTerminalRecord(string $table, string $terminalColumn): void
    {
        foreach ([2591999 => true, 2592000 => false, 2592001 => false] as $age => $retained) {
            $id = $this->insertTerminalRecord($table, $terminalColumn, $age);
            self::getContainer()->get(TechnicalDataCleanup::class)->run(5000);

            self::assertSame($retained ? 1 : 0, (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE id = ?', $table), [$id],
            ), $table.'.'.$terminalColumn.' at '.$age.' seconds');
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function terminalRecords(): iterable
    {
        yield 'expired session' => ['user_session', 'expires_at'];
        yield 'revoked session' => ['user_session', 'revoked_at'];
        yield 'expired invitation' => ['connect_invitation', 'expires_at'];
        yield 'accepted invitation' => ['connect_invitation', 'used_at'];
        yield 'expired token' => ['user_action_token', 'expires_at'];
        yield 'used token' => ['user_action_token', 'used_at'];
        yield 'invalidated token' => ['user_action_token', 'invalidated_at'];
    }

    #[DataProvider('outboxStates')]
    public function testOutboxRetentionProtectsItsTokenUntilThirtyDaysAfterCompletion(string $status, string $terminalColumn): void
    {
        foreach ([2591999 => true, 2592000 => false, 2592001 => false] as $age => $retained) {
            $token = $this->insertTerminalRecord('user_action_token', 'used_at', 5184000);
            $outbox = Uuid::v4()->toRfc4122();
            $this->connection->executeStatement(sprintf(<<<'SQL'
                INSERT INTO email_delivery_outbox (
                    id, user_action_token_id, recipient_email, template_key, encrypted_payload, status,
                    created_at, available_at, published_at, %s, publish_attempts
                ) VALUES (:id, :token, 'retention@example.test', 'ACTIVATE_ACCOUNT', decode('0102', 'hex'), :status,
                          CURRENT_TIMESTAMP - INTERVAL '90 days', CURRENT_TIMESTAMP - INTERVAL '90 days',
                          CURRENT_TIMESTAMP - INTERVAL '90 days',
                          date_trunc('second', CURRENT_TIMESTAMP) - CAST(:age AS integer) * INTERVAL '1 second', 1)
                SQL, $terminalColumn), ['id' => $outbox, 'token' => $token, 'status' => $status, 'age' => $age]);

            self::getContainer()->get(TechnicalDataCleanup::class)->run(5000);

            self::assertSame($retained ? 1 : 0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM email_delivery_outbox WHERE id = ?', [$outbox]));
            self::assertSame($retained ? 1 : 0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE id = ?', [$token]));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM email_delivery_outbox WHERE id = ? AND encrypted_payload IS NOT NULL', [$outbox]));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function outboxStates(): iterable
    {
        yield 'sent' => ['SENT', 'sent_at'];
        yield 'failed' => ['FAILED', 'failed_at'];
    }

    public function testIdempotencyIsRetainedUntilItsExactExpiry(): void
    {
        $user = $this->insertUser();
        foreach ([-1 => false, 0 => false, 1 => true] as $offset => $retained) {
            $id = Uuid::v4()->toRfc4122();
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO idempotency_record (
                    id, scope, user_id, operation, idempotency_key, request_hash,
                    response_status, response_body, created_at, expires_at
                ) VALUES (:id, :scope, :user, 'retention.test', :key, 'synthetic-digest', 200, '{}',
                          date_trunc('second', CURRENT_TIMESTAMP) - INTERVAL '24 hours' + CAST(:offset AS integer) * INTERVAL '1 second',
                          date_trunc('second', CURRENT_TIMESTAMP) + CAST(:offset AS integer) * INTERVAL '1 second')
                SQL, ['id' => $id, 'scope' => 'user:'.$user, 'user' => $user, 'key' => $id, 'offset' => $offset]);

            self::getContainer()->get(TechnicalDataCleanup::class)->run(5000);

            self::assertSame($retained ? 1 : 0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM idempotency_record WHERE id = ?', [$id]));
        }
    }

    public function testAnotherCleanupLockSkipsMutationButAllowsReadOnlyInspection(): void
    {
        $id = $this->insertTerminalRecord('user_session', 'expires_at', 2592000);
        $lockOwner = DriverManager::getConnection($this->connection->getParams());
        try {
            $lockOwner->beginTransaction();
            $lockOwner->executeQuery('SELECT pg_advisory_xact_lock(67113822, 22)');
            $cleanup = self::getContainer()->get(TechnicalDataCleanup::class);

            self::assertSame(['skipped' => 1], $cleanup->run(10));
            self::assertSame(1, $cleanup->run(10, true)['sessions']);
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE id = ?', [$id]));
        } finally {
            if ($lockOwner->isTransactionActive()) {
                $lockOwner->rollBack();
            }
            $lockOwner->close();
        }

        self::assertSame(1, (new TechnicalDataCleanup($this->connection))->run(10)['sessions']);
    }

    #[DataProvider('invalidBatchSizes')]
    public function testInvalidBatchDoesNotDeleteEligibleRecords(int $size): void
    {
        $id = $this->insertTerminalRecord('user_session', 'expires_at', 2592000);
        try {
            self::getContainer()->get(TechnicalDataCleanup::class)->run($size);
            self::fail('Invalid batch size was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE id = ?', [$id]));
        }
    }

    /** @return iterable<string, array{int}> */
    public static function invalidBatchSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above limit' => [5001];
    }

    private function insertUser(): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->connection->insert('app_user', [
            'id' => $id, 'name' => 'Retention boundary', 'email' => $id.'@example.test',
            'password_hash' => 'unused-test-hash', 'is_active' => true, 'created_at' => '2026-01-01T00:00:00Z',
        ]);

        return $id;
    }

    private function insertTerminalRecord(string $table, string $terminalColumn, int $age): string
    {
        $id = Uuid::v4()->toRfc4122();
        $user = $this->insertUser();
        $fields = match ($table) {
            'user_session' => ['user_id' => $user, 'refresh_token_hash' => 'session-'.$id],
            'connect_invitation' => ['created_by_id' => $user, 'token_hash' => 'invitation-'.$id],
            'user_action_token' => ['user_id' => $user, 'token_hash' => 'token-'.$id, 'purpose' => 'ACTIVATE_ACCOUNT'],
            default => throw new InvalidArgumentException('Unknown test record.'),
        };
        $now = new DateTimeImmutable((string) $this->connection->fetchOne('SELECT CURRENT_TIMESTAMP'));
        $this->connection->insert($table, [
            'id' => $id, ...$fields, 'created_at' => $now->modify('-90 days')->format('c'), 'expires_at' => $now->modify('+1 day')->format('c'),
        ]);
        $acceptedBy = 'used_at' === $terminalColumn && 'connect_invitation' === $table ? $this->insertUser() : null;
        $this->connection->executeStatement(sprintf(
            // Match the second precision of session/invitation timestamps without PostgreSQL rounding up.
            'UPDATE %s SET %s = date_trunc(\'second\', CURRENT_TIMESTAMP) - CAST(:age AS integer) * INTERVAL \'1 second\'%s WHERE id = :id',
            $table, $terminalColumn, null === $acceptedBy ? '' : ', accepted_by_id = :accepted_by',
        ), ['age' => $age, 'id' => $id, ...(null === $acceptedBy ? [] : ['accepted_by' => $acceptedBy])]);

        return $id;
    }
}
