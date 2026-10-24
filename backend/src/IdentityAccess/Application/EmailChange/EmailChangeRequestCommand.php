<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\EmailChange;

final readonly class EmailChangeRequestCommand
{
    public function __construct(public string $userId, public string $currentPassword, public string $newEmail)
    {
    }
}
