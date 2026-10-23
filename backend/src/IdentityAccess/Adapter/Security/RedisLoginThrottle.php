<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\LoginThrottlePort;
use InvalidArgumentException;
use Redis;
use RuntimeException;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final class RedisLoginThrottle implements LoginThrottlePort
{
    private const string CHECK_SCRIPT = <<<'LUA'
        local count = tonumber(redis.call('GET', KEYS[1]) or '0')
        if count < tonumber(ARGV[1]) then return 0 end
        local ttl = redis.call('PTTL', KEYS[1])
        if ttl <= 0 then
            redis.call('DEL', KEYS[1])
            return 0
        end
        return math.floor((ttl + 999) / 1000)
        LUA;

    private const string FAILURE_SCRIPT = <<<'LUA'
        local count = tonumber(redis.call('GET', KEYS[1]) or '0')
        local limit = tonumber(ARGV[1])
        if count >= limit then
            local ttl = redis.call('PTTL', KEYS[1])
            if ttl > 0 then return math.floor((ttl + 999) / 1000) end
            redis.call('DEL', KEYS[1])
            count = 0
        end
        count = count + 1
        redis.call('SET', KEYS[1], count, 'PX', ARGV[2])
        if count >= limit then return tonumber(ARGV[3]) end
        return 0
        LUA;

    private ?Redis $connection = null;

    public function __construct(
        private readonly string $redisUrl,
        private readonly int $failureLimit,
        private readonly int $cooldownSeconds,
    ) {
        if ($failureLimit < 1 || $cooldownSeconds < 1) {
            throw new InvalidArgumentException('Login throttle settings must be positive.');
        }
    }

    public function retryAfter(string $ip): ?int
    {
        return $this->asRetryAfter($this->redis()->eval(self::CHECK_SCRIPT, [$this->key($ip), $this->failureLimit], 1));
    }

    public function recordInvalidCredentials(string $ip): ?int
    {
        return $this->asRetryAfter($this->redis()->eval(self::FAILURE_SCRIPT, [
            $this->key($ip), $this->failureLimit, $this->cooldownSeconds * 1000, $this->cooldownSeconds,
        ], 1));
    }

    public function reset(string $ip): void
    {
        $this->redis()->del($this->key($ip));
    }

    private function redis(): Redis
    {
        if (null === $this->connection) {
            $connection = RedisAdapter::createConnection($this->redisUrl);
            if (!$connection instanceof Redis) {
                throw new RuntimeException('The login throttle requires the Redis extension.');
            }

            $this->connection = $connection;
        }

        return $this->connection;
    }

    private function key(string $ip): string
    {
        return 'equo:login:failure:'.hash('sha256', $ip);
    }

    private function asRetryAfter(mixed $value): ?int
    {
        if (!is_int($value)) {
            throw new RuntimeException('The login throttle returned an invalid result.');
        }

        return $value > 0 ? $value : null;
    }
}
