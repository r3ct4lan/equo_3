<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Logout;

final readonly class LogoutCurrentSessionCommand
{
    public function __construct(public string $refreshToken, public string $csrfToken)
    {
    }
}
