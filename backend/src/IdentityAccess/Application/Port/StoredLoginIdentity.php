<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

final readonly class StoredLoginIdentity
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        private string $passwordHash,
        public bool $isActive,
    ) {
    }

    public function verifyPassword(PasswordHashingPort $passwordHasher, string $plainPassword): bool
    {
        return $passwordHasher->verify($plainPassword, $this->passwordHash);
    }
}
