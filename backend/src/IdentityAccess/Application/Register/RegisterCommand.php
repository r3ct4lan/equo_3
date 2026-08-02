<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Register;

final readonly class RegisterCommand
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $idempotencyKey,
        public string $ipAddress,
    ) {
    }
}
