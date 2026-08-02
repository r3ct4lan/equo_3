<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use DateTimeImmutable;

interface ClockPort
{
    public function now(): DateTimeImmutable;
}
