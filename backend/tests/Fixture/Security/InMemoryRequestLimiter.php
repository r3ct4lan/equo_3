<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Security;

use App\Infrastructure\Application\Api\RequestLimiter;

final class InMemoryRequestLimiter implements RequestLimiter
{
    /** @var array<string, array{count: int, expires: float, blockedUntil: float}> */
    private array $states = [];

    public function consume(array $limits): ?int
    {
        $now = microtime(true);
        $remaining = 0;
        foreach ($limits as $key => $_) {
            $remaining = max($remaining, (int) ceil(($this->states[$key]['blockedUntil'] ?? 0) - $now));
        }
        if ($remaining > 0) {
            return $remaining;
        }

        $exceeded = false;
        foreach ($limits as $key => $limit) {
            $state = $this->states[$key] ?? ['count' => 0, 'expires' => 0.0, 'blockedUntil' => 0.0];
            if ($state['expires'] <= $now) {
                $state['count'] = 0;
            }
            if ($state['count'] >= $limit) {
                $state['blockedUntil'] = $now + 90;
                $exceeded = true;
            }
            $this->states[$key] = $state;
        }
        if ($exceeded) {
            return 90;
        }
        foreach ($limits as $key => $_) {
            if (0 === $this->states[$key]['count']) {
                $this->states[$key]['expires'] = $now + 90;
            }
            ++$this->states[$key]['count'];
        }

        return null;
    }
}
