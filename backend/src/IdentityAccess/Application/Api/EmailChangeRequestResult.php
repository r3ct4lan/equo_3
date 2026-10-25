<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

final readonly class EmailChangeRequestResult
{
    public string $status;

    public function __construct()
    {
        $this->status = 'email_confirmation_scheduled';
    }
}
