<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

final readonly class RegisterResult
{
    public function __construct(public UserView $user, public bool $activationRequired = true)
    {
    }

    /** @return array{user: array{id: string, name: string, email: string, isActive: bool, createdAt: string}, activationRequired: bool} */
    public function toArray(): array
    {
        return ['user' => $this->user->toArray(), 'activationRequired' => $this->activationRequired];
    }
}
