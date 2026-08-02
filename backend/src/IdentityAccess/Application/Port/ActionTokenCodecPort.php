<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface ActionTokenCodecPort
{
    public function issue(): IssuedActionToken;

    public function digest(string $publicToken): ?string;
}
