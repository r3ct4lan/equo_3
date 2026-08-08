<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface CsrfTokenCodecPort
{
    public function issue(string $sessionId): string;

    public function verify(string $sessionId, string $publicToken): bool;
}
