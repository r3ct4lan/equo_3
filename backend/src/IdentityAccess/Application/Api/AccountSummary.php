<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

final readonly class AccountSummary
{
    public function __construct(public string $id, public string $name, public bool $isActive)
    {
    }
}
