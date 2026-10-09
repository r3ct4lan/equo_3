<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineIdentityRepository;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineTransaction;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineUserSessionRepository;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Logout\LogoutAllSessions;
use App\IdentityAccess\Application\Port\ClockPort;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LogoutAllSessionsPersistenceTest extends KernelTestCase
{
    private const string USER_ID = '88000000-0000-4000-8000-000000000001';
    private const string FOREIGN_USER_ID = '88000000-0000-4000-8000-000000000002';
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->connection->beginTransaction();
        $this->now = new DateTimeImmutable('2026-10-09T12:00:00Z');
        foreach ([self::USER_ID, self::FOREIGN_USER_ID] as $userId) {
            $this->connection->insert('app_user', [
                'id' => $userId, 'name' => 'Logout All', 'email' => $userId.'@example.test',
                'password_hash' => 'not-used', 'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testBulkRevokeOnlyUnfinishedOwnedSessionsPreservesTerminalRowsAndFirstTimestamp(): void
    {
        $this->session(1, self::USER_ID, '2026-11-01 00:00:00+00');
        $this->session(2, self::USER_ID, '2026-11-01 00:00:00+00');
        $this->session(3, self::USER_ID, '2026-10-09 12:00:00+00');
        $this->session(4, self::USER_ID, '2026-11-01 00:00:00+00', '2026-10-02 00:00:00+00');
        $this->session(5, self::FOREIGN_USER_ID, '2026-11-01 00:00:00+00');

        $this->handler()->handle(self::USER_ID);
        $first = $this->connection->fetchAllAssociative('SELECT id, revoked_at FROM user_session ORDER BY id');
        self::assertNotNull($first[0]['revoked_at']);
        self::assertSame($first[0]['revoked_at'], $first[1]['revoked_at']);
        self::assertNull($first[2]['revoked_at']);
        self::assertSame('2026-10-02 00:00:00+00', $first[3]['revoked_at']);
        self::assertNull($first[4]['revoked_at']);

        $this->handler()->handle(self::USER_ID);
        self::assertSame($first, $this->connection->fetchAllAssociative('SELECT id, revoked_at FROM user_session ORDER BY id'));
    }

    public function testFailureAfterBulkUpdateRollsBackEveryRow(): void
    {
        $this->session(1, self::USER_ID, '2026-11-01 00:00:00+00');
        $this->session(2, self::USER_ID, '2026-11-01 00:00:00+00');
        $transaction = new DoctrineTransaction($this->entityManager);

        try {
            $transaction->run(function (): void {
                $this->handler()->handle(self::USER_ID);
                self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE revoked_at IS NOT NULL'));
                throw new RuntimeException('Controlled failure.');
            });
        } catch (RuntimeException $failure) {
            self::assertSame('Controlled failure.', $failure->getMessage());
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE revoked_at IS NOT NULL'));
    }

    public function testOwnerIsRecheckedDespitePreviouslyHydratedActiveRecord(): void
    {
        $repository = new DoctrineIdentityRepository($this->entityManager);
        self::assertTrue($repository->currentUserState(self::USER_ID)?->isActive);
        $this->connection->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [self::USER_ID]);
        $this->session(1, self::USER_ID, '2026-11-01 00:00:00+00');

        try {
            $this->handler()->handle(self::USER_ID);
            self::fail('Inactive owner must fail.');
        } catch (ApplicationFailure) {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE revoked_at IS NOT NULL'));
        }
    }

    private function handler(): LogoutAllSessions
    {
        $clock = $this->createStub(ClockPort::class);
        $clock->method('now')->willReturn($this->now);

        return new LogoutAllSessions(
            new DoctrineIdentityRepository($this->entityManager), new DoctrineUserSessionRepository($this->entityManager),
            new DoctrineTransaction($this->entityManager), $clock,
        );
    }

    private function session(int $number, string $userId, string $expiresAt, ?string $revokedAt = null): void
    {
        $this->connection->insert('user_session', [
            'id' => '88000000-0000-4000-8001-'.sprintf('%012d', $number), 'user_id' => $userId,
            'refresh_token_hash' => 'sha256:'.hash('sha256', (string) $number),
            'created_at' => '2026-10-01 00:00:00+00', 'expires_at' => $expiresAt, 'revoked_at' => $revokedAt,
        ]);
    }
}
