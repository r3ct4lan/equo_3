<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use Redis;
use RedisException;
use SensitiveParameter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TelegramOidcStateStore
{
    public const int TTL = 600;

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        #[SensitiveParameter]
        private string $redisUrl,
    ) {
    }

    public function put(string $state, string $browser, #[SensitiveParameter] string $verifier): void
    {
        try {
            $stored = $this->connection()->setex($this->key($state, $browser), self::TTL, $verifier);
        } catch (RedisException) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }

        if (true !== $stored) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }
    }

    public function consume(string $state, string $browser): ?string
    {
        if (1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $state) || 1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $browser)) {
            return null;
        }

        try {
            $verifier = $this->connection()->getDel($this->key($state, $browser));
        } catch (RedisException) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }

        return is_string($verifier) ? $verifier : null;
    }

    private function connection(): Redis
    {
        $connection = RedisAdapter::createConnection($this->redisUrl, ['class' => Redis::class, 'timeout' => 1.0, 'read_timeout' => 1.0]);
        if (!$connection instanceof Redis) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }

        return $connection;
    }

    private function key(string $state, string $browser): string
    {
        return 'equo:telegram:oidc:v1:'.hash('sha256', $state.':'.$browser);
    }
}
