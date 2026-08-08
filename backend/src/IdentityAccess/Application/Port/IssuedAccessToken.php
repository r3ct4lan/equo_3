<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

final readonly class IssuedAccessToken
{
    public function __construct(public string $accessToken, public int $expiresIn)
    {
    }
}
