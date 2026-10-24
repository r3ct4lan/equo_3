<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface EmailChangeRateLimitPort
{
    public function emailChangeRequestRetryAfter(string $userId): ?int;

    public function emailChangeConfirmationRetryAfter(string $ip, string $tokenFingerprint): ?int;
}
