<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

final readonly class CurrentUserState
{
    public function __construct(public string $id, public bool $isActive)
    {
    }
}
