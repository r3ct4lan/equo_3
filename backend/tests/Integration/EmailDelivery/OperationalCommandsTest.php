<?php

declare(strict_types=1);

namespace App\Tests\Integration\EmailDelivery;

use App\Infrastructure\EmailDelivery\Command\EmailOutboxStatusCommand;
use App\Infrastructure\EmailDelivery\Command\ListFailedEmailDeliveriesCommand;
use App\Infrastructure\EmailDelivery\Command\RelayEmailOutboxCommand;
use App\Infrastructure\EmailDelivery\Command\ReplayEmailOutboxCommand;
use App\Infrastructure\EmailDelivery\Messaging\SendUserActionEmail;
use App\Infrastructure\EmailDelivery\Monitoring\EmailOutboxAlertThresholds;
use App\Infrastructure\EmailDelivery\Monitoring\EmailOutboxMonitor;
use App\Infrastructure\EmailDelivery\Monitoring\FailedEmailDeliveries;
use App\Infrastructure\EmailDelivery\Outbox\OutboxRelay;
use App\Infrastructure\EmailDelivery\Replay\EmailOutboxReplay;
use App\Infrastructure\Retention\Command\CleanupTechnicalDataCommand;
use App\Infrastructure\Retention\TechnicalDataCleanup;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

final class OperationalCommandsTest extends KernelTestCase
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

    #[DataProvider('thresholds')]
    public function testStatusReportsSafeMetricsAndTheExitCodeUsedByMonitoring(string $configuration, string $status, int $exitCode): void
    {
        $tester = new CommandTester(new EmailOutboxStatusCommand(
            self::getContainer()->get(EmailOutboxMonitor::class), new EmailOutboxAlertThresholds($configuration),
        ));

        self::assertSame($exitCode, $tester->execute([]));
        $result = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($status, $result['alert_status']);
        $keys = array_keys($result);
        sort($keys);
        $expected = [...EmailOutboxMonitor::METRIC_NAMES, 'alert_status', 'alerts'];
        sort($expected);
        self::assertSame($expected, $keys);
        self::assertSame(0, $result['pending_count']);
        self::assertStringNotContainsString('recipient_email', $tester->getDisplay());
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function thresholds(): iterable
    {
        yield 'unconfigured' => ['', 'unconfigured', 0];
        yield 'healthy' => ['{"pending_count":{"warning":1,"critical":2}}', 'ok', 0];
        yield 'warning boundary' => ['{"pending_count":{"warning":0,"critical":1}}', 'warning', 1];
        yield 'critical boundary' => ['{"pending_count":{"warning":0,"critical":0}}', 'critical', 2];
    }

    public function testFailureListingBoundsTheTransportReadAndExposesOnlySafeIdentifiers(): void
    {
        $transport = $this->createMock(InspectableFailureTransport::class);
        $deliveryId = Uuid::v4()->toRfc4122();
        $transport->expects(self::once())->method('all')->with(1)->willReturn([
            (new Envelope(new SendUserActionEmail($deliveryId)))->with(new TransportMessageIdStamp(45)),
            new Envelope(new stdClass()),
            new Envelope(new SendUserActionEmail(Uuid::v4()->toRfc4122())),
        ]);
        $tester = new CommandTester(new ListFailedEmailDeliveriesCommand(new FailedEmailDeliveries($transport)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '1']));
        self::assertSame([['transport_message_id' => '45', 'delivery_id' => $deliveryId]], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidLimits')]
    public function testFailureListingRejectsInvalidLimitWithoutReadingTransport(string $limit): void
    {
        $transport = $this->createMock(InspectableFailureTransport::class);
        $transport->expects(self::never())->method('all');
        $tester = new CommandTester(new ListFailedEmailDeliveriesCommand(new FailedEmailDeliveries($transport)));

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => $limit]));
        self::assertSame("Limit must be an integer between 1 and 100.\n", $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'above limit' => ['101'];
        yield 'fraction' => ['1.5'];
        yield 'not an integer' => ['private-invalid-value'];
    }

    public function testRelayOnEmptyOutboxIsASuccessfulNoOp(): void
    {
        $tester = new CommandTester(new RelayEmailOutboxCommand(self::getContainer()->get(OutboxRelay::class)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("Processed 0 outbox deliveries.\n", $tester->getDisplay());
    }

    #[DataProvider('invalidDeliveryIds')]
    public function testReplayRejectsMalformedIdentifiersWithoutEchoingTheInput(string $value): void
    {
        $tester = new CommandTester(new ReplayEmailOutboxCommand(self::getContainer()->get(EmailOutboxReplay::class)));

        self::assertSame(Command::INVALID, $tester->execute(['delivery-id' => $value]));
        self::assertSame("Delivery ID is invalid.\n", $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDeliveryIds(): iterable
    {
        yield 'arbitrary input' => ['private-invalid-value'];
        yield 'missing uuid version' => ['00000000-0000-0000-8000-000000000001'];
        yield 'bad variant' => ['00000000-0000-4000-0000-000000000001'];
        yield 'trailing newline' => ["00000000-0000-4000-8000-000000000001\n"];
    }

    public function testReplayRefusesAnUnknownDelivery(): void
    {
        $tester = new CommandTester(new ReplayEmailOutboxCommand(self::getContainer()->get(EmailOutboxReplay::class)));

        self::assertSame(Command::FAILURE, $tester->execute(['delivery-id' => Uuid::v4()->toRfc4122()]));
        self::assertSame("Replay refused: delivery is terminal or its action token is no longer deliverable.\n", $tester->getDisplay());
    }

    #[DataProvider('invalidBatchSizes')]
    public function testCleanupRejectsInvalidBatchWithoutDeletingEligibleRows(string $size): void
    {
        $id = $this->expiredIdempotency();
        $tester = new CommandTester(new CleanupTechnicalDataCommand(self::getContainer()->get(TechnicalDataCleanup::class)));

        self::assertSame(Command::INVALID, $tester->execute(['--batch-size' => $size]));
        self::assertSame("Batch size must be an integer between 1 and 5000.\n", $tester->getDisplay());
        self::assertSame(1, $this->countIdempotency($id));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBatchSizes(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'above limit' => ['5001'];
        yield 'fraction' => ['1.5'];
        yield 'not an integer' => ['private-invalid-value'];
    }

    public function testCleanupDryRunPreservesRowsAndRealRunRemovesThem(): void
    {
        $id = $this->expiredIdempotency();
        $tester = new CommandTester(new CleanupTechnicalDataCommand(self::getContainer()->get(TechnicalDataCleanup::class)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--batch-size' => '1']));
        self::assertStringContainsString('Eligible idempotency: 1', $tester->getDisplay());
        self::assertSame(1, $this->countIdempotency($id));

        self::assertSame(Command::SUCCESS, $tester->execute(['--batch-size' => '1']));
        self::assertStringContainsString('Processed idempotency: 1', $tester->getDisplay());
        self::assertSame(0, $this->countIdempotency($id));
    }

    private function countIdempotency(string $id): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM idempotency_record WHERE id = ?', [$id]);
    }

    private function expiredIdempotency(): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO idempotency_record (
                id, scope, operation, idempotency_key, request_hash, response_status, response_body, created_at, expires_at
            ) VALUES (:id, 'public', 'retention.test', :key, 'synthetic-digest', 200, '{}',
                      CURRENT_TIMESTAMP - INTERVAL '2 days', CURRENT_TIMESTAMP - INTERVAL '1 day')
            SQL, ['id' => $id, 'key' => $id]);

        return $id;
    }
}

interface InspectableFailureTransport extends TransportInterface, ListableReceiverInterface
{
}
