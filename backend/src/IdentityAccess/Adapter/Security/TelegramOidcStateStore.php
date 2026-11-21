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

    /** @param array{userId:string, sessionId:string, original:?int, deactivate:bool}|null $account */
    public function put(string $state, string $browser, #[SensitiveParameter] string $verifier, ?array $account = null): void
    {
        try {
            $stored = $this->connection()->setex($this->key($state, $browser), self::TTL, json_encode(['verifier' => $verifier, 'account' => $account], JSON_THROW_ON_ERROR));
        } catch (RedisException) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }

        if (true !== $stored) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }
    }

    /** @return array{verifier:string, account:array{userId:string, sessionId:string, original:?int, deactivate:bool}|null}|null */
    public function consume(string $state, string $browser): ?array
    {
        if (1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $state) || 1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $browser)) {
            return null;
        }

        try {
            $verifier = $this->connection()->getDel($this->key($state, $browser));
        } catch (RedisException) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }

        if (!is_string($verifier)) {
            return null;
        }
        $data = json_decode($verifier, true);
        if (!is_array($data) || !is_string($data['verifier'] ?? null)) {
            return null;
        }
        $account = $data['account'] ?? null;
        if (null === $account) {
            return ['verifier' => $data['verifier'], 'account' => null];
        }
        if (!is_array($account) || !is_string($account['userId'] ?? null) || !is_string($account['sessionId'] ?? null)) {
            return null;
        }
        $original = $account['original'] ?? null;

        return ['verifier' => $data['verifier'], 'account' => [
            'userId' => $account['userId'],
            'sessionId' => $account['sessionId'],
            'original' => is_int($original) ? $original : null,
            'deactivate' => true === ($account['deactivate'] ?? false),
        ]];
    }

    public function putReactivation(#[SensitiveParameter] string $browser, int $telegramId): void
    {
        try {
            $stored = $this->connection()->setex($this->reactivationKey($browser), self::TTL, (string) $telegramId);
        } catch (RedisException) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }
        if (true !== $stored) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }
    }

    public function consumeReactivation(#[SensitiveParameter] string $browser): ?int
    {
        if (1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $browser)) {
            return null;
        }

        try {
            $id = $this->connection()->getDel($this->reactivationKey($browser));
        } catch (RedisException) {
            throw new ApplicationFailure(ApplicationFailureCode::TelegramUnavailable);
        }

        return is_string($id) && ctype_digit($id) ? (int) $id : null;
    }

    private function reactivationKey(#[SensitiveParameter] string $browser): string
    {
        return 'equo:telegram:reactivate:v1:'.hash('sha256', $browser);
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
