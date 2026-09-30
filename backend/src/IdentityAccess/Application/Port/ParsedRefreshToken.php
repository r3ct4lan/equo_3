<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

final readonly class ParsedRefreshToken
{
    public function __construct(public string $sessionId, public string $tokenHash)
    {
    }
}
