<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AccountDeactivation;

final readonly class DeactivateAccountCommand
{
    public function __construct(public string $userId, public string $currentPassword)
    {
    }
}
