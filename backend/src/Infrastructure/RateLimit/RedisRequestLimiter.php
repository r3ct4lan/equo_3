<?php

declare(strict_types=1);

namespace App\Infrastructure\RateLimit;

use App\Infrastructure\Application\Api\RequestLimiter;
use Redis;
use RuntimeException;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final class RedisRequestLimiter implements RequestLimiter
{
    private const string SCRIPT = <<<'LUA'
        local remaining = 0
        for i = 1, #KEYS do
            local ttl = redis.call('PTTL', KEYS[i] .. ':blocked')
            if ttl > remaining then remaining = ttl end
        end
        if remaining > 0 then return math.floor((remaining + 999) / 1000) end

        local exceeded = false
        for i = 1, #KEYS do
            if tonumber(redis.call('GET', KEYS[i]) or '0') >= tonumber(ARGV[i]) then
                exceeded = true
                redis.call('SET', KEYS[i] .. ':blocked', '1', 'PX', 90000)
            end
        end
        if exceeded then return 90 end

        for i = 1, #KEYS do
            local count = redis.call('INCR', KEYS[i])
            if count == 1 then redis.call('PEXPIRE', KEYS[i], 90000) end
        end
        return 0
        LUA;

    private ?Redis $connection = null;

    public function __construct(private readonly string $redisUrl)
    {
    }

    public function consume(array $limits): ?int
    {
        if ([] === $limits) {
            throw new RuntimeException('At least one rate limit is required.');
        }
        $keys = [];
        $values = [];
        foreach ($limits as $key => $limit) {
            if ($limit < 1) {
                throw new RuntimeException('Rate limits must be positive.');
            }
            $keys[] = 'equo:request-limit:v2:'.hash('sha256', $key);
            $values[] = $limit;
        }
        $result = $this->redis()->eval(self::SCRIPT, [...$keys, ...$values], count($keys));
        if (!is_int($result)) {
            throw new RuntimeException('The request limiter returned an invalid result.');
        }

        return $result > 0 ? $result : null;
    }

    private function redis(): Redis
    {
        if (null === $this->connection) {
            $connection = RedisAdapter::createConnection($this->redisUrl);
            if (!$connection instanceof Redis) {
                throw new RuntimeException('The request limiter requires the Redis extension.');
            }
            $this->connection = $connection;
        }

        return $this->connection;
    }
}
