<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;

final readonly class StoredPasswordResetToken
{
    public function __construct(
        public string $id,
        public string $userId,
        public UserActionTokenPurpose $purpose,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt,
        public ?DateTimeImmutable $invalidatedAt,
    ) {
    }
}
