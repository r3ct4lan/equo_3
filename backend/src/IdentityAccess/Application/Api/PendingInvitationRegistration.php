<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

interface PendingInvitationRegistration
{
    public function resolve(string $token): string;
}
