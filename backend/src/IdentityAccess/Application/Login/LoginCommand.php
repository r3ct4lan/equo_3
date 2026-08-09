<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Login;

final readonly class LoginCommand
{
    public function __construct(
        public string $email,
        public string $password,
        public string $ipAddress,
    ) {
    }
}
