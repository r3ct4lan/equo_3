<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\ClockPort;
use App\Tests\Fixture\Security\InMemoryLoginThrottle;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InMemoryLoginThrottleTest extends TestCase
{
    public function testPartialFailuresExpireAfterSixtySecondsWithoutErrors(): void
    {
        $clock = new class implements ClockPort {
            public DateTimeImmutable $time;

            public function __construct()
            {
                $this->time = new DateTimeImmutable('2026-10-22T12:00:00Z');
            }

            public function now(): DateTimeImmutable
            {
                return $this->time;
            }
        };
        $throttle = new InMemoryLoginThrottle($clock);

        for ($attempt = 0; $attempt < 4; ++$attempt) {
            self::assertNull($throttle->recordInvalidCredentials('192.0.2.1'));
        }
        $clock->time = $clock->time->modify('+60 seconds');
        self::assertNull($throttle->recordInvalidCredentials('192.0.2.1'));
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertNull($throttle->recordInvalidCredentials('192.0.2.1'));
        }
        self::assertSame(60, $throttle->recordInvalidCredentials('192.0.2.1'));

        $clock->time = $clock->time->modify('+25 seconds');
        self::assertSame(35, $throttle->retryAfter('192.0.2.1'));
        self::assertSame(35, $throttle->recordInvalidCredentials('192.0.2.1'));
        $clock->time = $clock->time->modify('+35 seconds');
        self::assertNull($throttle->retryAfter('192.0.2.1'));
        self::assertNull($throttle->recordInvalidCredentials('192.0.2.1'));
    }
}
