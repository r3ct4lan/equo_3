<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\Access;

use DateTimeImmutable;
use DomainException;

final readonly class UserActionToken
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $tokenHash,
        public UserActionTokenPurpose $purpose,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($expiresAt <= $createdAt) {
            throw new DomainException('An action token must expire after it is created.');
        }
    }
}
