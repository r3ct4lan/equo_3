<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface LoginThrottlePort
{
    public function retryAfter(string $ip): ?int;

    public function recordInvalidCredentials(string $ip): ?int;

    public function reset(string $ip): void;
}
