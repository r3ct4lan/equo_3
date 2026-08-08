<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Login;

final readonly class LoginUserView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public bool $isActive,
    ) {
    }

    /** @return array{id: string, name: string, email: string, isActive: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'isActive' => $this->isActive,
        ];
    }
}
