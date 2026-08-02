<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface RateLimitPort
{
    public function registrationRetryAfter(string $ip, string $normalizedEmail): ?int;

    public function activationRetryAfter(string $ip, string $tokenFingerprint): ?int;
}
