<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Monitoring;

use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class EmailOutboxMonitor
{
    /** @var list<string> */
    public const METRIC_NAMES = [
        'pending_count',
        'pending_oldest_age_seconds',
        'published_count',
        'published_oldest_age_seconds',
        'failed_count',
        'failure_transport_count',
        'relay_error_count',
        'smtp_failure_count',
    ];

    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        $outbox = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE status = 'PENDING') AS pending_count,
                COALESCE(MAX(EXTRACT(EPOCH FROM CURRENT_TIMESTAMP - created_at)) FILTER (WHERE status = 'PENDING'), 0)::integer AS pending_oldest_age_seconds,
                COUNT(*) FILTER (WHERE status = 'PUBLISHED') AS published_count,
                COALESCE(MAX(EXTRACT(EPOCH FROM CURRENT_TIMESTAMP - published_at)) FILTER (WHERE status = 'PUBLISHED' AND published_at IS NOT NULL), 0)::integer AS published_oldest_age_seconds,
                COUNT(*) FILTER (WHERE status = 'FAILED') AS failed_count,
                COUNT(*) FILTER (WHERE last_error = 'Message broker publication failed.') AS relay_error_count,
                COUNT(*) FILTER (WHERE last_error = 'SMTP delivery retries were exhausted.') AS smtp_failure_count
            FROM email_delivery_outbox
            SQL);

        if (false === $outbox) {
            throw new RuntimeException('Email outbox status is unavailable.');
        }

        return [
            'pending_count' => (int) $outbox['pending_count'],
            'pending_oldest_age_seconds' => (int) $outbox['pending_oldest_age_seconds'],
            'published_count' => (int) $outbox['published_count'],
            'published_oldest_age_seconds' => (int) $outbox['published_oldest_age_seconds'],
            'failed_count' => (int) $outbox['failed_count'],
            'failure_transport_count' => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'"),
            'relay_error_count' => (int) $outbox['relay_error_count'],
            'smtp_failure_count' => (int) $outbox['smtp_failure_count'],
        ];
    }
}
