<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserSessionRecord;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Domain\Access\UserSession;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

final class UserSessionPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private UserSessionRepositoryPort $repository;
    private TransactionPort $transaction;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->repository = $container->get(UserSessionRepositoryPort::class);
        $this->transaction = $container->get(TransactionPort::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->entityManager->clear();

        parent::tearDown();
    }

    public function testSessionCanBePersistedReadLockedRotatedAndRevoked(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $userId = '81000000-0000-4000-8000-000000000001';
        $sessionId = '81000000-0000-4000-8000-000000000002';
        $this->insertUser($userId);

        $session = UserSession::create($sessionId, $userId, $this->hash('first'), $createdAt);

        $this->transaction->run(function () use ($session): void {
            $this->repository->add($session);
        });
        $this->entityManager->clear();

        $stored = $this->transaction->run(fn (): ?UserSession => $this->repository->findByRefreshTokenHashForUpdate($this->hash('first')));
        self::assertInstanceOf(UserSession::class, $stored);
        self::assertSame($sessionId, $stored->id);
        self::assertSame($userId, $stored->userId);
        self::assertTrue($stored->isActive($createdAt));

        $this->transaction->run(function () use ($createdAt): void {
            $locked = $this->repository->findByRefreshTokenHashForUpdate($this->hash('first'));
            self::assertInstanceOf(UserSession::class, $locked);
            $locked->rotate($this->hash('second'), $createdAt->modify('+1 hour'));
            $locked->revoke($createdAt->modify('+2 hours'));
            $this->repository->save($locked);
        });
        $this->entityManager->clear();

        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ?',
            [$this->hash('first')],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ? AND revoked_at IS NOT NULL',
            [$this->hash('second')],
        ));
    }

    public function testSessionStoresOnlyHashAndSerializerHidesIt(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $userId = '81000000-0000-4000-8000-000000000011';
        $sessionId = '81000000-0000-4000-8000-000000000012';
        $plainRefreshToken = 'rt.'.strtr(base64_encode(str_repeat('x', 32)), '+/', '-_');
        $this->insertUser($userId);

        $this->transaction->run(function () use ($createdAt, $userId, $sessionId): void {
            $this->repository->add(UserSession::create($sessionId, $userId, $this->hash('stored'), $createdAt));
        });

        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ?',
            [$plainRefreshToken],
        ));
        self::assertSame($this->hash('stored'), $this->connection->fetchOne(
            'SELECT refresh_token_hash FROM user_session WHERE id = ?',
            [$sessionId],
        ));

        $record = $this->entityManager->find(UserSessionRecord::class, $sessionId);
        self::assertInstanceOf(UserSessionRecord::class, $record);

        $serialized = self::getContainer()->get(SerializerInterface::class)->normalize($record);
        self::assertIsArray($serialized);
        self::assertArrayNotHasKey('refreshTokenHash', $serialized);
    }

    public function testSessionRequiresExistingUser(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection->insert('user_session', [
            'id' => '81000000-0000-4000-8000-000000000021',
            'user_id' => '81000000-0000-4000-8000-000000000099',
            'refresh_token_hash' => $this->hash('missing-user'),
            'created_at' => '2026-08-08 12:00:00+00',
            'expires_at' => '2026-09-07 12:00:00+00',
            'revoked_at' => null,
        ]);
    }

    public function testSessionRefreshHashIsUnique(): void
    {
        $userId = '81000000-0000-4000-8000-000000000031';
        $this->insertUser($userId);
        $this->insertSession('81000000-0000-4000-8000-000000000032', $userId, $this->hash('same'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertSession('81000000-0000-4000-8000-000000000033', $userId, $this->hash('same'));
    }

    public function testSessionMustExpireAfterItWasCreated(): void
    {
        $userId = '81000000-0000-4000-8000-000000000041';
        $this->insertUser($userId);

        $this->expectException(DriverException::class);

        $this->insertSession(
            '81000000-0000-4000-8000-000000000042',
            $userId,
            $this->hash('bad-expiry'),
            '2026-08-08 11:00:00+00',
        );
    }

    public function testLockedRefreshLookupBlocksCompetingWrite(): void
    {
        $userId = '81000000-0000-4000-8000-000000000051';
        $sessionId = '81000000-0000-4000-8000-000000000052';
        $this->insertUser($userId);
        $this->insertSession($sessionId, $userId, $this->hash('locked'));
        $this->connection->commit();

        $this->transaction->run(function () use ($sessionId): void {
            $locked = $this->repository->findByRefreshTokenHashForUpdate($this->hash('locked'));
            self::assertInstanceOf(UserSession::class, $locked);

            $secondConnection = DriverManager::getConnection(
                $this->connection->getParams(),
                $this->connection->getConfiguration(),
            );
            $secondConnection->beginTransaction();

            try {
                $secondConnection->executeStatement("SET LOCAL lock_timeout = '100ms'");
                $secondConnection->executeStatement(
                    'UPDATE user_session SET refresh_token_hash = ? WHERE id = ?',
                    [$this->hash('competing'), $sessionId],
                );
                self::fail('The competing write must wait for the locked session row.');
            } catch (DriverException) {
                self::assertSame($this->hash('locked'), $this->connection->fetchOne(
                    'SELECT refresh_token_hash FROM user_session WHERE id = ?',
                    [$sessionId],
                ));
            } finally {
                if ($secondConnection->isTransactionActive()) {
                    $secondConnection->rollBack();
                }

                $secondConnection->close();
            }
        });

        $this->connection->delete('user_session', ['id' => $sessionId]);
        $this->connection->delete('app_user', ['id' => $userId]);
        $this->connection->beginTransaction();
    }

    public function testFailedRotationRollsBackHashReplacement(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $userId = '81000000-0000-4000-8000-000000000061';
        $sessionId = '81000000-0000-4000-8000-000000000062';
        $this->insertUser($userId);

        $this->transaction->run(function () use ($createdAt, $userId, $sessionId): void {
            $this->repository->add(UserSession::create($sessionId, $userId, $this->hash('rollback-old'), $createdAt));
        });
        $this->entityManager->clear();

        $failure = null;

        try {
            $this->transaction->run(function () use ($createdAt): void {
                $session = $this->repository->findByRefreshTokenHashForUpdate($this->hash('rollback-old'));
                self::assertInstanceOf(UserSession::class, $session);
                $session->rotate($this->hash('rollback-new'), $createdAt->modify('+1 hour'));
                $this->repository->save($session);

                throw new RuntimeException('Controlled session rotation failure.');
            });
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertSame('Controlled session rotation failure.', $failure->getMessage());
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ?',
            [$this->hash('rollback-old')],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ?',
            [$this->hash('rollback-new')],
        ));
    }

    private function insertUser(string $id): void
    {
        $this->connection->insert('app_user', [
            'id' => $id,
            'name' => 'Session User',
            'email' => $id.'@example.test',
            'password_hash' => 'password-hash',
            'is_active' => true,
            'created_at' => '2026-08-08 12:00:00+00',
        ]);
    }

    private function insertSession(
        string $id,
        string $userId,
        string $refreshTokenHash,
        string $expiresAt = '2026-09-07 12:00:00+00',
    ): void {
        $this->connection->insert('user_session', [
            'id' => $id,
            'user_id' => $userId,
            'refresh_token_hash' => $refreshTokenHash,
            'created_at' => '2026-08-08 12:00:00+00',
            'expires_at' => $expiresAt,
            'revoked_at' => null,
        ]);
    }

    private function hash(string $seed): string
    {
        return 'sha256:'.hash('sha256', $seed);
    }
}
