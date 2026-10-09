<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\PasswordReset;

final readonly class PasswordResetRequestCommand
{
    public function __construct(public string $email, public string $ipAddress)
    {
    }
}
