<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

final readonly class RefreshRequestCredentials
{
    public function __construct(public string $refreshToken, public string $csrfToken)
    {
    }
}
