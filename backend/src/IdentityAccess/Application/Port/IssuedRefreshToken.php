<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

final readonly class IssuedRefreshToken
{
    public function __construct(
        public string $publicToken,
        public string $tokenHash,
        public string $sessionId,
    ) {
    }
}
