<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use DateTimeImmutable;

final readonly class VerifiedAccessToken
{
    public function __construct(
        public string $userId,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public string $jwtId,
    ) {
    }
}
