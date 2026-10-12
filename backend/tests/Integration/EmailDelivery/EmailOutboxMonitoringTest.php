<?php

declare(strict_types=1);

namespace App\Tests\Integration\EmailDelivery;

use App\Infrastructure\EmailDelivery\Monitoring\EmailOutboxMonitor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmailOutboxMonitoringTest extends KernelTestCase
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

    public function testStatusContainsOnlyAggregateMetricsIncludingFailureQueueSize(): void
    {
        $this->connection->insert('messenger_messages', [
            'body' => '{"message":"sensitive-test-marker"}',
            'headers' => '{}',
            'queue_name' => 'failed',
            'created_at' => '2026-10-01 00:00:00',
            'available_at' => '2026-10-01 00:00:00',
            'delivered_at' => null,
        ]);

        $snapshot = self::getContainer()->get(EmailOutboxMonitor::class)->snapshot();

        self::assertSame(0, $snapshot['pending_count']);
        self::assertSame(0, $snapshot['published_count']);
        self::assertSame(0, $snapshot['failed_count']);
        self::assertSame(1, $snapshot['failure_transport_count']);
        self::assertSame(0, $snapshot['relay_error_count']);
        self::assertSame(0, $snapshot['smtp_failure_count']);
        self::assertStringNotContainsString('sensitive-test-marker', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
