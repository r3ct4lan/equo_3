<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Messaging;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Throwable;

final class UserActionEmailRetryStrategy implements RetryStrategyInterface
{
    private const array DELAYS = [60_000, 300_000, 900_000, 3_600_000, 21_600_000];

    public function isRetryable(Envelope $message, ?Throwable $throwable = null): bool
    {
        return $message->getMessage() instanceof SendUserActionEmail
            && RedeliveryStamp::getRetryCountFromEnvelope($message) < count(self::DELAYS);
    }

    public function getWaitingTime(Envelope $message, ?Throwable $throwable = null): int
    {
        return self::DELAYS[RedeliveryStamp::getRetryCountFromEnvelope($message)] ?? 21_600_000;
    }
}
