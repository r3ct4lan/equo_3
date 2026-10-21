<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

interface PendingInvitationAccess
{
    public function forUser(string $userId): ?string;

    public function clear(string $userId): void;
}
