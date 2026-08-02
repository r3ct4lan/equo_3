<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface PayloadCipherPort
{
    /** @param array<string, string> $payload */
    public function encrypt(array $payload): string;
}
