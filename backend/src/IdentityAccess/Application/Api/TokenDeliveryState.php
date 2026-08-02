<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;

final readonly class TokenDeliveryState
{
    public function __construct(
        public UserActionTokenPurpose $purpose,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt,
        public ?DateTimeImmutable $invalidatedAt,
        public bool $current,
    ) {
    }

    public function isDeliverableAt(DateTimeImmutable $now): bool
    {
        return UserActionTokenPurpose::ActivateAccount === $this->purpose
            && $this->current
            && null === $this->usedAt
            && null === $this->invalidatedAt
            && $this->expiresAt > $now;
    }
}
