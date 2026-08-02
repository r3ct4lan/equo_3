<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface RequestFingerprintPort
{
    public function create(string $canonicalRequest): string;

    public function matches(string $storedFingerprint, string $canonicalRequest): bool;
}
