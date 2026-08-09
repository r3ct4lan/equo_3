<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface RefreshTokenCodecPort
{
    public function issue(): IssuedRefreshToken;

    public function digest(string $publicToken): ?string;
}
