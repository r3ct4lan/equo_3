<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Authorization;

use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;

final readonly class ActivationTokenAccess
{
    public function __construct(
        public string $userId,
        public UserActionTokenPurpose $purpose,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt = null,
        public ?DateTimeImmutable $invalidatedAt = null,
    ) {
    }
}
