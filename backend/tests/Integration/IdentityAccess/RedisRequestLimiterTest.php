<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\Infrastructure\RateLimit\RedisRequestLimiter;
use PHPUnit\Framework\TestCase;
use Redis;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final class RedisRequestLimiterTest extends TestCase
{
    public function testRejectedRequestsDoNotExtendBlockOrConsumeOtherQuota(): void
    {
        $url = getenv('REDIS_URL');
        self::assertIsString($url);
        $redis = RedisAdapter::createConnection($url);
        self::assertInstanceOf(Redis::class, $redis);
        $limiter = new RedisRequestLimiter($url);
        $prefix = 'test-'.bin2hex(random_bytes(16));
        $a = $prefix.':a';
        $b = $prefix.':b';
        $keyA = 'equo:request-limit:v2:'.hash('sha256', $a);
        $keyB = 'equo:request-limit:v2:'.hash('sha256', $b);

        try {
            self::assertNull($limiter->consume([$a => 1, $b => 2]));
            self::assertSame(90, $limiter->consume([$a => 1, $b => 2]));
            $redis->pExpire($keyA.':blocked', 4000);
            self::assertSame(4, $limiter->consume([$a => 1, $b => 2]));
            self::assertLessThanOrEqual(4000, $redis->pTTL($keyA.':blocked'));
            self::assertNull($limiter->consume([$b => 2]));
            self::assertSame(90, $limiter->consume([$b => 2]));
            $redis->pExpire($keyA, 1);
            $redis->pExpire($keyA.':blocked', 1);
            $redis->pExpire($keyB, 1);
            $redis->pExpire($keyB.':blocked', 1);
            usleep(10000);
            self::assertNull($limiter->consume([$a => 1, $b => 2]));
        } finally {
            $redis->del($keyA, $keyA.':blocked', $keyB, $keyB.':blocked');
        }
    }
}
