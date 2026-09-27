<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Domain\Access\UserActionToken;
use DateTimeImmutable;

interface ActivationRequestRepositoryPort
{
    public function accountForUpdate(string $normalizedEmail): ?ActivationRequestAccount;

    public function replaceActivationToken(UserActionToken $token, DateTimeImmutable $invalidatedAt): void;
}
