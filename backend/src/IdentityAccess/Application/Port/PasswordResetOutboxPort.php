<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use DateTimeImmutable;

interface PasswordResetOutboxPort
{
    public function enqueuePasswordReset(string $deliveryId, string $tokenId, string $recipientEmail, string $encryptedPayload, DateTimeImmutable $now): void;
}
