<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use DateTimeImmutable;

interface EmailLinkOutboxPort
{
    public function enqueueEmailLink(string $deliveryId, string $tokenId, string $email, string $encrypted, DateTimeImmutable $now): void;
}
