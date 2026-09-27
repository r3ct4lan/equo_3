<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\ActivationRequest;

final readonly class ActivationRequestCommand
{
    public function __construct(public string $email, public string $password)
    {
    }
}
