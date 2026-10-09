<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

final readonly class PasswordResetRequestResult
{
    public string $status;

    public function __construct()
    {
        $this->status = 'password_reset_email_scheduled';
    }
}
