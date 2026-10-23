<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Security;

use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\LoginThrottlePort;

final class InMemoryLoginThrottle implements LoginThrottlePort
{
    /** @var array<string, array{count: int, expiresAt: int}> */
    private array $states = [];

    public function __construct(
        private readonly ClockPort $clock,
        private readonly int $failureLimit = 5,
        private readonly int $cooldownSeconds = 60,
    ) {
    }

    public function retryAfter(string $ip): ?int
    {
        $state = $this->activeState($ip);
        if (null === $state || $state['count'] < $this->failureLimit) {
            return null;
        }

        return $state['expiresAt'] - $this->clock->now()->getTimestamp();
    }

    public function recordInvalidCredentials(string $ip): ?int
    {
        $retryAfter = $this->retryAfter($ip);
        if (null !== $retryAfter) {
            return $retryAfter;
        }

        $count = ($this->activeState($ip)['count'] ?? 0) + 1;
        $this->states[$ip] = [
            'count' => $count,
            'expiresAt' => $this->clock->now()->getTimestamp() + $this->cooldownSeconds,
        ];

        return $count >= $this->failureLimit ? $this->cooldownSeconds : null;
    }

    public function reset(string $ip): void
    {
        unset($this->states[$ip]);
    }

    /** @return array{count: int, expiresAt: int}|null */
    private function activeState(string $ip): ?array
    {
        $state = $this->states[$ip] ?? null;
        if (null !== $state && $state['expiresAt'] <= $this->clock->now()->getTimestamp()) {
            unset($this->states[$ip]);

            return null;
        }

        return $state;
    }
}
