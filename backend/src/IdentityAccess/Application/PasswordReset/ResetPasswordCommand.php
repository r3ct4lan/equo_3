<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\PasswordReset;

final readonly class ResetPasswordCommand
{
    public function __construct(public string $token, public string $newPassword, public string $ipAddress)
    {
    }
}
