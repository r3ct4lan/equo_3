<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface RefreshTokenCodecPort
{
    public function issueForSession(string $sessionId): IssuedRefreshToken;

    public function parse(string $publicToken): ?ParsedRefreshToken;
}
