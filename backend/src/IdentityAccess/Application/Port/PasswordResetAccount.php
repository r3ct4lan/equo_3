<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

final readonly class PasswordResetAccount
{
    public function __construct(public string $id, public string $email, public string $locale = 'en')
    {
    }
}
