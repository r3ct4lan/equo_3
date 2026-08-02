<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

use DateTimeImmutable;

interface TokenDeliveryQuery
{
    public function state(string $tokenId, DateTimeImmutable $now): ?TokenDeliveryState;
}
