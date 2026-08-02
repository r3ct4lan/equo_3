<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\System;

use App\IdentityAccess\Application\Port\UuidPort;
use Symfony\Component\Uid\Uuid;

final class SymfonyUuid implements UuidPort
{
    public function generate(): string
    {
        return (string) Uuid::v7();
    }
}
