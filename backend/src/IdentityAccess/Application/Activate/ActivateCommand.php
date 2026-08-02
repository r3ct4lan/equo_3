<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Activate;

final readonly class ActivateCommand
{
    public function __construct(public string $token, public string $ipAddress)
    {
    }
}
