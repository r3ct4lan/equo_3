<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\EmailChange;

final readonly class EmailChangeConfirmationCommand
{
    public function __construct(public string $token, public string $ipAddress)
    {
    }
}
