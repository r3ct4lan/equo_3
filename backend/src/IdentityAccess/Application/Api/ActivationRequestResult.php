<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

final readonly class ActivationRequestResult
{
    public function __construct(public string $status = 'activation_email_scheduled')
    {
    }
}
