<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Refresh;

final readonly class RefreshCommand
{
    public function __construct(public string $refreshToken, public string $csrfToken)
    {
    }
}
