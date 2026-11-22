<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface SessionAccessPolicyPort
{
    public function allows(string $userId, ?string $sessionId): bool;
}
