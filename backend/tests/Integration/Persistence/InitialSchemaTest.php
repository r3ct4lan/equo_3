<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserActionTokenRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryStatus;
use App\Infrastructure\Idempotency\Persistence\Doctrine\Record\IdempotencyRecord;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InitialSchemaTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
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

    public function testRecordsCanBeStoredAndRead(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-31T12:00:00+00:00');
        $user = new UserRecord(
            '00000000-0000-4000-8000-000000000001',
            'Ada Lovelace',
            'ada@example.test',
            'password-hash',
            false,
            $createdAt,
        );
        $token = new UserActionTokenRecord(
            '00000000-0000-4000-8000-000000000002',
            $user,
            'token-hash',
            UserActionTokenPurpose::ActivateAccount,
            ['locale' => 'en'],
            $createdAt,
            $createdAt->modify('+1 hour'),
        );
        $idempotency = new IdempotencyRecord(
            '00000000-0000-4000-8000-000000000003',
            'anonymous',
            $user->id(),
            'register',
            'request-key',
            'request-hash',
            202,
            ['status' => 'accepted'],
            $createdAt,
            $createdAt->modify('+24 hours'),
        );
        $outbox = new EmailDeliveryOutboxRecord(
            '00000000-0000-4000-8000-000000000004',
            $token->id(),
            $user->email(),
            'activate-account',
            'encrypted-payload',
            EmailDeliveryStatus::Pending,
            $createdAt,
            $createdAt,
            null,
            null,
            null,
            0,
            null,
        );

        $this->entityManager->persist($user);
        $this->entityManager->persist($token);
        $this->entityManager->persist($idempotency);
        $this->entityManager->persist($outbox);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $storedToken = $this->entityManager->find(UserActionTokenRecord::class, $token->id());
        $storedIdempotency = $this->entityManager->find(IdempotencyRecord::class, $idempotency->id());
        $storedOutbox = $this->entityManager->find(EmailDeliveryOutboxRecord::class, $outbox->id());

        self::assertInstanceOf(UserActionTokenRecord::class, $storedToken);
        self::assertSame($user->id(), $storedToken->user()->id());
        self::assertSame(UserActionTokenPurpose::ActivateAccount, $storedToken->purpose());
        self::assertSame(['locale' => 'en'], $storedToken->payload());

        self::assertInstanceOf(IdempotencyRecord::class, $storedIdempotency);
        self::assertSame($user->id(), $storedIdempotency->userId());
        self::assertSame(['status' => 'accepted'], $storedIdempotency->responseBody());

        self::assertInstanceOf(EmailDeliveryOutboxRecord::class, $storedOutbox);
        self::assertSame($token->id(), $storedOutbox->userActionTokenId());
        self::assertSame(EmailDeliveryStatus::Pending, $storedOutbox->status());
        self::assertSame('encrypted-payload', $storedOutbox->encryptedPayload());
    }

    public function testUserEmailIsRequired(): void
    {
        $this->expectException(NotNullConstraintViolationException::class);

        $this->insertUser('00000000-0000-4000-8000-000000000011', null);
    }

    public function testUserEmailIsUnique(): void
    {
        $this->insertUser('00000000-0000-4000-8000-000000000021', 'same@example.test');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertUser('00000000-0000-4000-8000-000000000022', 'same@example.test');
    }

    public function testUserEmailMustNotBeBlank(): void
    {
        $this->expectException(DriverException::class);

        $this->insertUser('00000000-0000-4000-8000-000000000023', '   ');
    }

    public function testTokenRequiresExistingUser(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->insertToken(
            '00000000-0000-4000-8000-000000000031',
            '00000000-0000-4000-8000-000000000099',
            'missing-user-token',
        );
    }

    public function testOnlyOneUnfinishedTokenPerUserAndPurposeIsAllowed(): void
    {
        $userId = '00000000-0000-4000-8000-000000000041';
        $this->insertUser($userId, 'token-owner@example.test');
        $this->insertToken(
            '00000000-0000-4000-8000-000000000042',
            $userId,
            'first-token',
        );

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertToken(
            '00000000-0000-4000-8000-000000000043',
            $userId,
            'second-token',
        );
    }

    public function testTokenMustExpireAfterItWasCreated(): void
    {
        $userId = '00000000-0000-4000-8000-000000000044';
        $this->insertUser($userId, 'expired-token-owner@example.test');

        $this->expectException(DriverException::class);

        $this->insertToken(
            '00000000-0000-4000-8000-000000000045',
            $userId,
            'invalid-expiry-token',
            '2026-07-31 11:00:00+00',
        );
    }

    public function testIdempotencyKeyIsUniqueWithinScopeAndOperation(): void
    {
        $values = [
            'scope' => 'anonymous',
            'user_id' => null,
            'operation' => 'register',
            'idempotency_key' => 'same-key',
            'request_hash' => 'hash',
            'response_status' => null,
            'response_body' => null,
            'created_at' => '2026-07-31 12:00:00+00',
            'expires_at' => '2026-08-01 12:00:00+00',
        ];

        $this->connection->insert('idempotency_record', ['id' => '00000000-0000-4000-8000-000000000051'] + $values);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->insert('idempotency_record', ['id' => '00000000-0000-4000-8000-000000000052'] + $values);
    }

    public function testIdempotencyRecordMustExpireAfterItWasCreated(): void
    {
        $this->expectException(DriverException::class);

        $this->connection->insert('idempotency_record', [
            'id' => '00000000-0000-4000-8000-000000000053',
            'scope' => 'public',
            'user_id' => null,
            'operation' => 'register',
            'idempotency_key' => 'invalid-expiry',
            'request_hash' => 'hash',
            'response_status' => null,
            'response_body' => null,
            'created_at' => '2026-07-31 12:00:00+00',
            'expires_at' => '2026-07-31 11:00:00+00',
        ]);
    }

    public function testOutboxRequiresExistingToken(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection->insert('email_delivery_outbox', [
            'id' => '00000000-0000-4000-8000-000000000061',
            'user_action_token_id' => '00000000-0000-4000-8000-000000000099',
            'recipient_email' => 'recipient@example.test',
            'template_key' => 'activate-account',
            'encrypted_payload' => null,
            'status' => 'PENDING',
            'created_at' => '2026-07-31 12:00:00+00',
            'available_at' => '2026-07-31 12:00:00+00',
            'published_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'publish_attempts' => 0,
            'last_error' => null,
        ]);
    }

    public function testOutboxRejectsUnknownStatus(): void
    {
        $userId = '00000000-0000-4000-8000-000000000062';
        $tokenId = '00000000-0000-4000-8000-000000000063';
        $this->insertUser($userId, 'outbox-owner@example.test');
        $this->insertToken($tokenId, $userId, 'outbox-token');

        $this->expectException(DriverException::class);

        $this->connection->insert('email_delivery_outbox', [
            'id' => '00000000-0000-4000-8000-000000000064',
            'user_action_token_id' => $tokenId,
            'recipient_email' => 'recipient@example.test',
            'template_key' => 'activate-account',
            'encrypted_payload' => null,
            'status' => 'UNKNOWN',
            'created_at' => '2026-07-31 12:00:00+00',
            'available_at' => '2026-07-31 12:00:00+00',
            'published_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'publish_attempts' => 0,
            'last_error' => null,
        ]);
    }

    private function insertUser(string $id, ?string $email): void
    {
        $this->connection->insert('app_user', [
            'id' => $id,
            'name' => 'Test User',
            'email' => $email,
            'password_hash' => 'password-hash',
            'is_active' => 'false',
            'created_at' => '2026-07-31 12:00:00+00',
        ]);
    }

    private function insertToken(
        string $id,
        string $userId,
        string $tokenHash,
        string $expiresAt = '2026-07-31 13:00:00+00',
    ): void
    {
        $this->connection->insert('user_action_token', [
            'id' => $id,
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'purpose' => 'ACTIVATE_ACCOUNT',
            'payload' => null,
            'created_at' => '2026-07-31 12:00:00+00',
            'expires_at' => $expiresAt,
            'used_at' => null,
            'invalidated_at' => null,
        ]);
    }
}
