<?php

declare(strict_types=1);

namespace App\Tests\Integration\EmailDelivery;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserActionTokenRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Application\Api\TokenDeliveryQuery;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\Infrastructure\EmailDelivery\Messaging\FinalDeliveryFailureSubscriber;
use App\Infrastructure\EmailDelivery\Messaging\SendUserActionEmail;
use App\Infrastructure\EmailDelivery\Messaging\SendUserActionEmailHandler;
use App\Infrastructure\EmailDelivery\Messaging\UserActionEmailRetryStrategy;
use App\Infrastructure\EmailDelivery\Outbox\OutboxRelay;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryStatus;
use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EmailDeliveryFlowTest extends KernelTestCase
{
    private const string PAYLOAD_KEY = 'ZW1haWwtcGF5bG9hZC1kZXYta2V5LTMyLWJ5dGVzISE=';

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

    public function testRelayMarksPublishedOnlyAfterTransportAcceptsDeliveryIdMessage(): void
    {
        $outbox = $this->fixture(EmailDeliveryStatus::Pending);
        $relay = self::getContainer()->get(OutboxRelay::class);

        self::assertSame(1, $relay->relayBatch());
        self::assertSame(EmailDeliveryStatus::Published, $outbox->status());
        self::assertSame(1, $outbox->publishAttempts());
        self::assertNotNull($outbox->publishedAt());

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendUserActionEmail::class, $message);
        self::assertSame($outbox->id(), $message->deliveryId);
    }

    public function testConsumerUsesSynchronousMailerAndClearsPayloadAfterHandoff(): void
    {
        $outbox = $this->fixture(EmailDeliveryStatus::Published);
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('send');
        $handler = new SendUserActionEmailHandler(
            $this->entityManager,
            self::getContainer()->get(TokenDeliveryQuery::class),
            new PayloadCipher(self::PAYLOAD_KEY),
            $transport,
            'http://localhost',
            'no-reply@equo.local',
        );

        $handler(new SendUserActionEmail($outbox->id()));
        $handler(new SendUserActionEmail($outbox->id()));

        self::assertSame(EmailDeliveryStatus::Sent, $outbox->status());
        self::assertNotNull($outbox->sentAt());
        self::assertNull($outbox->encryptedPayload());
    }

    public function testConsumerSkipsAlreadyFailedDeliveryWithoutCallingMailer(): void
    {
        $outbox = $this->fixture(EmailDeliveryStatus::Failed);
        $failedAt = $outbox->failedAt();
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $handler = new SendUserActionEmailHandler(
            $this->entityManager,
            self::getContainer()->get(TokenDeliveryQuery::class),
            new PayloadCipher(self::PAYLOAD_KEY),
            $transport,
            'http://localhost',
            'no-reply@equo.local',
        );

        $handler(new SendUserActionEmail($outbox->id()));

        self::assertSame(EmailDeliveryStatus::Failed, $outbox->status());
        self::assertSame($failedAt?->format('U'), $outbox->failedAt()?->format('U'));
        self::assertNull($outbox->encryptedPayload());
    }

    public function testRelayPublicationFailureKeepsDurableIntentAndSchedulesThirtySecondRetry(): void
    {
        $outbox = $this->fixture(EmailDeliveryStatus::Pending);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new RuntimeException('broker secret detail'));
        $relay = new OutboxRelay(
            $this->entityManager,
            $bus,
            self::getContainer()->get(TokenDeliveryQuery::class),
        );
        $before = new DateTimeImmutable('now');

        self::assertSame(1, $relay->relayBatch());
        self::assertSame(EmailDeliveryStatus::Pending, $outbox->status());
        self::assertSame(1, $outbox->publishAttempts());
        self::assertGreaterThanOrEqual($before->modify('+29 seconds'), $outbox->availableAt());
        self::assertSame('Message broker publication failed.', $outbox->lastError());
        self::assertStringNotContainsString('secret detail', (string) $outbox->lastError());
    }

    public function testConsumerRejectsExpiredTokenWithoutCallingMailer(): void
    {
        $outbox = $this->fixture(EmailDeliveryStatus::Published, expired: true);
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $handler = new SendUserActionEmailHandler(
            $this->entityManager,
            self::getContainer()->get(TokenDeliveryQuery::class),
            new PayloadCipher(self::PAYLOAD_KEY),
            $transport,
            'http://localhost',
            'no-reply@equo.local',
        );

        $handler(new SendUserActionEmail($outbox->id()));

        self::assertSame(EmailDeliveryStatus::Failed, $outbox->status());
        self::assertNull($outbox->encryptedPayload());
    }

    public function testConsumerRejectsInvalidatedTokenWithoutCallingMailer(): void
    {
        $outbox = $this->fixture(EmailDeliveryStatus::Published, invalidated: true);
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $handler = new SendUserActionEmailHandler(
            $this->entityManager,
            self::getContainer()->get(TokenDeliveryQuery::class),
            new PayloadCipher(self::PAYLOAD_KEY),
            $transport,
            'http://localhost',
            'no-reply@equo.local',
        );

        $handler(new SendUserActionEmail($outbox->id()));

        self::assertSame(EmailDeliveryStatus::Failed, $outbox->status());
        self::assertNull($outbox->encryptedPayload());
        self::assertSame('Action token is no longer deliverable.', $outbox->lastError());
    }

    public function testSmtpFailureRollsBackAndFinalFailureClearsSecret(): void
    {
        $outbox = $this->fixture(EmailDeliveryStatus::Published);
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('send')->willThrowException(new RuntimeException('SMTP secret detail'));
        $handler = new SendUserActionEmailHandler(
            $this->entityManager,
            self::getContainer()->get(TokenDeliveryQuery::class),
            new PayloadCipher(self::PAYLOAD_KEY),
            $transport,
            'http://localhost',
            'no-reply@equo.local',
        );

        try {
            $handler(new SendUserActionEmail($outbox->id()));
            self::fail('SMTP failure must be retried by Messenger.');
        } catch (RuntimeException) {
            self::assertSame(EmailDeliveryStatus::Published, $outbox->status());
            self::assertNotNull($outbox->encryptedPayload());
        }

        $event = new WorkerMessageFailedEvent(
            new Envelope(new SendUserActionEmail($outbox->id())),
            'async',
            new RuntimeException('SMTP secret detail'),
        );
        (new FinalDeliveryFailureSubscriber($this->entityManager))->onMessageFailed($event);

        self::assertSame(EmailDeliveryStatus::Failed, $outbox->status());
        self::assertNull($outbox->encryptedPayload());
        self::assertSame('SMTP delivery retries were exhausted.', $outbox->lastError());
    }

    public function testRetryScheduleMatchesNormativeFiveDelays(): void
    {
        $strategy = new UserActionEmailRetryStrategy();
        $expected = [60_000, 300_000, 900_000, 3_600_000, 21_600_000];

        foreach ($expected as $retryCount => $delay) {
            $envelope = (new Envelope(new SendUserActionEmail('delivery')))->with(new RedeliveryStamp($retryCount));
            self::assertTrue($strategy->isRetryable($envelope));
            self::assertSame($delay, $strategy->getWaitingTime($envelope));
        }

        $exhausted = (new Envelope(new SendUserActionEmail('delivery')))->with(new RedeliveryStamp(5));
        self::assertFalse($strategy->isRetryable($exhausted));
    }

    public function testRelayRetryBackoffDoublesAndCapsAtThirtyMinutes(): void
    {
        $outbox = $this->fixture(EmailDeliveryStatus::Pending);
        $now = new DateTimeImmutable('2026-08-02T10:00:00Z');

        foreach ([30, 60, 120, 240, 480, 960, 1800, 1800] as $expectedDelay) {
            $outbox->beginPublish();
            $outbox->deferAfterPublishFailure($now);

            self::assertEquals($now->modify(sprintf('+%d seconds', $expectedDelay)), $outbox->availableAt());
            self::assertSame('Message broker publication failed.', $outbox->lastError());
        }
    }

    public function testUncertainSmtpOutcomeCanRetryWithoutDuplicatingBusinessState(): void
    {
        $outbox = $this->fixture(EmailDeliveryStatus::Published);
        $deliveries = 0;
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('send')->willReturnCallback(static function () use (&$deliveries): null {
            ++$deliveries;

            if (1 === $deliveries) {
                throw new RuntimeException('Connection lost after an uncertain SMTP handoff.');
            }

            return null;
        });
        $handler = new SendUserActionEmailHandler(
            $this->entityManager,
            self::getContainer()->get(TokenDeliveryQuery::class),
            new PayloadCipher(self::PAYLOAD_KEY),
            $transport,
            'http://localhost',
            'no-reply@equo.local',
        );

        try {
            $handler(new SendUserActionEmail($outbox->id()));
            self::fail('The first uncertain handoff must leave the delivery retryable.');
        } catch (RuntimeException) {
            self::assertSame(EmailDeliveryStatus::Published, $outbox->status());
        }

        $handler(new SendUserActionEmail($outbox->id()));

        self::assertSame(2, $deliveries);
        self::assertSame(EmailDeliveryStatus::Sent, $outbox->status());
        self::assertNull($outbox->encryptedPayload());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_user'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_action_token'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM email_delivery_outbox'));
    }

    private function fixture(
        EmailDeliveryStatus $status,
        bool $expired = false,
        bool $invalidated = false,
    ): EmailDeliveryOutboxRecord {
        $now = new DateTimeImmutable('now');
        $suffix = bin2hex(random_bytes(6));
        $user = new UserRecord(
            sprintf('00000000-0000-4000-8000-%s', $suffix),
            'Delivery User',
            $suffix.'@example.test',
            'password-hash',
            false,
            $now,
        );
        $token = new UserActionTokenRecord(
            sprintf('10000000-0000-4000-8000-%s', $suffix),
            $user,
            'v1:'.$suffix,
            UserActionTokenPurpose::ActivateAccount,
            null,
            $now->modify('-2 hours'),
            $expired ? $now->modify('-1 hour') : $now->modify('+1 hour'),
            null,
            $invalidated ? $now->modify('-1 hour') : null,
        );
        $terminal = in_array($status, [EmailDeliveryStatus::Sent, EmailDeliveryStatus::Failed], true);
        $outbox = new EmailDeliveryOutboxRecord(
            sprintf('20000000-0000-4000-8000-%s', $suffix),
            $token->id(),
            $user->email(),
            'activate-account',
            $terminal ? null : (new PayloadCipher(self::PAYLOAD_KEY))->encrypt(['token' => 'v1.public-token']),
            $status,
            $now,
            $now,
            EmailDeliveryStatus::Published === $status ? $now : null,
            EmailDeliveryStatus::Sent === $status ? $now : null,
            EmailDeliveryStatus::Failed === $status ? $now : null,
            EmailDeliveryStatus::Published === $status ? 1 : 0,
            null,
        );

        $this->entityManager->persist($user);
        $this->entityManager->persist($token);
        $this->entityManager->persist($outbox);
        $this->entityManager->flush();

        return $outbox;
    }
}
