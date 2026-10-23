<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Adapter\Security\RedisLoginThrottle;
use PHPUnit\Framework\TestCase;

final class RedisLoginThrottleTest extends TestCase
{
    public function testFailuresAndFixedCooldownUseRedis(): void
    {
        $redisUrl = getenv('REDIS_URL');
        self::assertIsString($redisUrl);
        $throttle = new RedisLoginThrottle($redisUrl, 5, 2);
        $ip = 'test-'.bin2hex(random_bytes(16));

        try {
            for ($attempt = 0; $attempt < 4; ++$attempt) {
                self::assertNull($throttle->recordInvalidCredentials($ip));
            }
            self::assertSame(2, $throttle->recordInvalidCredentials($ip));
            self::assertSame(2, $throttle->recordInvalidCredentials($ip));
            sleep(1);
            self::assertSame(1, $throttle->retryAfter($ip));
            self::assertSame(1, $throttle->recordInvalidCredentials($ip));
            sleep(1);
            self::assertNull($throttle->retryAfter($ip));
            self::assertNull($throttle->recordInvalidCredentials($ip));
        } finally {
            $throttle->reset($ip);
        }
    }
}
