<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\User;

use DateTimeImmutable;
use DomainException;

final class User
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly EmailAddress $email,
        public readonly string $passwordHash,
        private bool $active,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function register(
        string $id,
        string $name,
        EmailAddress $email,
        string $passwordHash,
        DateTimeImmutable $createdAt,
    ): self {
        if ('' === trim($name)) {
            throw new DomainException('A user name must not be blank.');
        }

        return new self($id, $name, $email, $passwordHash, false, $createdAt);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function activate(): void
    {
        if ($this->active) {
            throw new DomainException('Only an inactive account can be activated.');
        }

        $this->active = true;
    }
}
