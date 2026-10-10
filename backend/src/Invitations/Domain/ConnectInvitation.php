<?php

declare(strict_types=1);

namespace App\Invitations\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class ConnectInvitation
{
    public function __construct(
        public readonly string $id,
        public readonly string $createdById,
        public readonly string $tokenHash,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
        public private(set) ?DateTimeImmutable $usedAt = null,
        public private(set) ?string $acceptedById = null,
    ) {
        if ($expiresAt <= $createdAt || (null === $usedAt) !== (null === $acceptedById) || $createdById === $acceptedById) {
            throw new InvalidArgumentException('Invitation lifecycle is invalid.');
        }
    }

    public function isAvailable(DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && $this->expiresAt > $now;
    }

    public function accept(string $userId, DateTimeImmutable $now): void
    {
        if ($userId === $this->createdById || !$this->isAvailable($now)) {
            throw new InvalidArgumentException('Invitation cannot be accepted.');
        }
        $this->usedAt = $now;
        $this->acceptedById = $userId;
    }
}
