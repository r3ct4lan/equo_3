<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\System;

use App\IdentityAccess\Application\Port\ClockPort;
use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements ClockPort
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
