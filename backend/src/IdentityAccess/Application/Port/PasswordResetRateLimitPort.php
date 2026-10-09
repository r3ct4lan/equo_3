<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface PasswordResetRateLimitPort
{
    public function passwordResetRequestRetryAfter(string $ip, string $normalizedEmail): ?int;

    public function passwordResetRetryAfter(string $ip, string $tokenFingerprint): ?int;
}
