<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineIdentityRepository;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineTransaction;
use App\IdentityAccess\Adapter\Security\VersionedRequestFingerprint;
use App\IdentityAccess\Adapter\System\SymfonyUuid;
use App\IdentityAccess\Adapter\System\SystemClock;
use App\IdentityAccess\Application\ActivationRequest\ActivationRequestCommand;
use App\IdentityAccess\Application\ActivationRequest\RequestActivation;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\EmailOutboxPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\IdentityAccess\Application\Register\RegisterCommand;
use App\IdentityAccess\Application\Register\RegisterUser;
use App\IdentityAccess\Domain\User\PasswordPolicy;
use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use App\Infrastructure\Idempotency\Persistence\Doctrine\DoctrineIdempotency;
use App\Infrastructure\Idempotency\Persistence\Doctrine\Record\IdempotencyRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FirstVerticalSliceTransactionTest extends KernelTestCase
{
    public function testIdempotencyReplaysBeforeAndRestartsExactlyAtTwentyFourHours(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $key = '49000000-0000-4000-8000-'.sprintf('%012d', random_int(1, 999999999999));
        $createdAt = new DateTimeImmutable('2026-08-02T10:00:00Z');
        $idempotency = new DoctrineIdempotency($entityManager, new SymfonyUuid(), $this->requestFingerprint());

        try {
            self::assertNull($idempotency->begin('public', 'register', $key, 'first-hash', $createdAt));
            $idempotency->complete('public', 'register', $key, 201, ['result' => 'created'], null);
            $entityManager->flush();
            $entityManager->clear();

            $beforeExpiry = (new DoctrineIdempotency(
                $entityManager,
                new SymfonyUuid(),
                $this->requestFingerprint(),
            ))->begin(
                'public',
                'register',
                $key,
                'first-hash',
                $createdAt->modify('+24 hours -1 microsecond'),
            );
            self::assertNotNull($beforeExpiry);
            self::assertSame(201, $beforeExpiry->status);
            self::assertSame(['result' => 'created'], $beforeExpiry->body);
            $entityManager->clear();

            self::assertNull((new DoctrineIdempotency(
                $entityManager,
                new SymfonyUuid(),
                $this->requestFingerprint(),
            ))->begin(
                'public',
                'register',
                $key,
                'second-hash',
                $createdAt->modify('+24 hours'),
            ));
            $entityManager->flush();

            $record = $entityManager->getRepository(IdempotencyRecord::class)->findOneBy([
                'scope' => 'public',
                'operation' => 'register',
                'idempotencyKey' => $key,
            ]);
            self::assertInstanceOf(IdempotencyRecord::class, $record);
            self::assertStringStartsWith('v1:', $record->requestHash());
            self::assertNull($record->responseStatus());
            self::assertNull($record->responseBody());
            self::assertEquals($createdAt->modify('+24 hours'), $record->createdAt());
            self::assertEquals($createdAt->modify('+48 hours'), $record->expiresAt());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $entityManager->clear();
        }
    }

    public function testRegistrationFailureRollsBackEveryRecord(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $uuid = new SymfonyUuid();
        $email = 'rollback-register-'.bin2hex(random_bytes(6)).'@example.test';
        $idempotencyKey = '50000000-0000-4000-8000-'.sprintf('%012d', random_int(1, 999999999999));
        $rateLimit = $this->createStub(RateLimitPort::class);
        $rateLimit->method('registrationRetryAfter')->willReturn(null);
        $failingOutbox = new class implements EmailOutboxPort {
            public function enqueueActivation(
                string $deliveryId,
                string $tokenId,
                string $recipientEmail,
                string $encryptedPayload,
                DateTimeImmutable $now,
            ): void {
                throw new RuntimeException('Controlled outbox failure.');
            }
        };

        $handler = new RegisterUser(
            new PasswordPolicy(),
            $container->get(PasswordHashingPort::class),
            $container->get(ActionTokenCodecPort::class),
            $container->get(PayloadCipher::class),
            new DoctrineIdentityRepository($entityManager),
            new DoctrineIdempotency($entityManager, $uuid, $this->requestFingerprint()),
            $failingOutbox,
            $rateLimit,
            new DoctrineTransaction($entityManager),
            new SystemClock(),
            $uuid,
        );

        try {
            $handler->handle(new RegisterCommand(
                'Rollback User',
                $email,
                'A2345678901!',
                $idempotencyKey,
                '203.0.113.10',
            ));
            self::fail('The controlled persistence failure must escape the use case.');
        } catch (RuntimeException $exception) {
            self::assertSame('Controlled outbox failure.', $exception->getMessage());
        }

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', [$email]));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM idempotency_record WHERE idempotency_key = ?',
            [$idempotencyKey],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM user_action_token WHERE user_id IN (SELECT id FROM app_user WHERE email = ?)',
            [$email],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM email_delivery_outbox WHERE recipient_email = ?',
            [$email],
        ));
    }

    public function testActivationFailureAfterFlushRollsBackUserAndTokenTogether(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $userId = '60000000-0000-4000-8000-'.sprintf('%012d', random_int(1, 999999999999));
        $tokenId = '70000000-0000-4000-8000-'.sprintf('%012d', random_int(1, 999999999999));
        $email = 'rollback-activate-'.bin2hex(random_bytes(6)).'@example.test';
        $now = new DateTimeImmutable('2026-08-02T10:00:00Z');

        $connection->insert('app_user', [
            'id' => $userId,
            'name' => 'Rollback Activation',
            'email' => $email,
            'password_hash' => 'test-hash',
            'is_active' => 0,
            'created_at' => $now->format('Y-m-d H:i:sP'),
        ]);
        $connection->insert('user_action_token', [
            'id' => $tokenId,
            'user_id' => $userId,
            'token_hash' => 'v1:rollback-'.bin2hex(random_bytes(16)),
            'purpose' => 'ACTIVATE_ACCOUNT',
            'payload' => null,
            'created_at' => $now->format('Y-m-d H:i:sP'),
            'expires_at' => $now->modify('+24 hours')->format('Y-m-d H:i:sP'),
            'used_at' => null,
            'invalidated_at' => null,
        ]);

        $repository = new DoctrineIdentityRepository($entityManager);
        $transaction = new DoctrineTransaction($entityManager);

        try {
            $transaction->run(function () use ($repository, $entityManager, $tokenId, $userId, $now): void {
                $repository->activate($tokenId, $userId, $now);
                $entityManager->flush();

                throw new RuntimeException('Controlled commit-boundary failure.');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('Controlled commit-boundary failure.', $exception->getMessage());
        }

        self::assertSame(false, $connection->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [$userId]));
        self::assertNull($connection->fetchOne('SELECT used_at FROM user_action_token WHERE id = ?', [$tokenId]));

        $connection->delete('user_action_token', ['id' => $tokenId]);
        $connection->delete('app_user', ['id' => $userId]);
    }

    public function testActivationRequestOutboxFailureRollsBackReplacementAndInvalidation(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $uuid = new SymfonyUuid();
        $userId = $uuid->generate();
        $oldTokenId = $uuid->generate();
        $email = 'rollback-activation-request-'.bin2hex(random_bytes(6)).'@example.test';
        $password = 'Correct password!';
        $now = new DateTimeImmutable('now');
        $connection->insert('app_user', [
            'id' => $userId,
            'name' => 'Rollback Activation Request',
            'email' => $email,
            'password_hash' => $container->get(PasswordHashingPort::class)->hash($password),
            'is_active' => 0,
            'created_at' => $now->modify('-1 day')->format('Y-m-d H:i:sP'),
        ]);
        $connection->insert('user_action_token', [
            'id' => $oldTokenId,
            'user_id' => $userId,
            'token_hash' => 'v1:rollback-request-'.bin2hex(random_bytes(16)),
            'purpose' => 'ACTIVATE_ACCOUNT',
            'payload' => null,
            'created_at' => $now->modify('-1 hour')->format('Y-m-d H:i:sP'),
            'expires_at' => $now->modify('+23 hours')->format('Y-m-d H:i:sP'),
            'used_at' => null,
            'invalidated_at' => null,
        ]);
        $failingOutbox = new class implements EmailOutboxPort {
            public function enqueueActivation(
                string $deliveryId,
                string $tokenId,
                string $recipientEmail,
                string $encryptedPayload,
                DateTimeImmutable $now,
            ): void {
                throw new RuntimeException('Controlled activation-request outbox failure.');
            }
        };
        $rateLimit = $this->createStub(RateLimitPort::class);
        $rateLimit->method('activationRequestRetryAfter')->willReturn(null);
        $handler = new RequestActivation(
            new DoctrineIdentityRepository($entityManager),
            $container->get(PasswordHashingPort::class),
            $container->get(ActionTokenCodecPort::class),
            $container->get(PayloadCipher::class),
            $failingOutbox,
            $rateLimit,
            new DoctrineTransaction($entityManager),
            new SystemClock(),
            $uuid,
        );

        try {
            try {
                $handler->handle(new ActivationRequestCommand($email, $password, '203.0.113.20'));
                self::fail('The controlled outbox failure must roll back the activation request.');
            } catch (RuntimeException $exception) {
                self::assertSame('Controlled activation-request outbox failure.', $exception->getMessage());
            }

            self::assertSame(1, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM user_action_token WHERE user_id = ?',
                [$userId],
            ));
            self::assertNull($connection->fetchOne(
                'SELECT invalidated_at FROM user_action_token WHERE id = ?',
                [$oldTokenId],
            ));
            self::assertSame(0, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM email_delivery_outbox WHERE user_action_token_id IN (SELECT id FROM user_action_token WHERE user_id = ?)',
                [$userId],
            ));
        } finally {
            $connection->executeStatement(
                'DELETE FROM email_delivery_outbox WHERE user_action_token_id IN (SELECT id FROM user_action_token WHERE user_id = ?)',
                [$userId],
            );
            $connection->delete('user_action_token', ['user_id' => $userId]);
            $connection->delete('app_user', ['id' => $userId]);
        }
    }

    private function requestFingerprint(): VersionedRequestFingerprint
    {
        return new VersionedRequestFingerprint(
            'v1',
            '{"v1":"aWRlbXBvdGVuY3ktZmluZ2VycHJpbnQtdGVzdC1rZXktMzI="}',
        );
    }
}
