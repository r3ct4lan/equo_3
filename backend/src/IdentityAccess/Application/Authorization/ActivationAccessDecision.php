<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Authorization;

use LogicException;

final readonly class ActivationAccessDecision
{
    private function __construct(
        private ?string $userId,
        public ?ActivationAccessDenial $denial,
    ) {
    }

    public static function allow(string $userId): self
    {
        return new self($userId, null);
    }

    public static function deny(ActivationAccessDenial $denial): self
    {
        return new self(null, $denial);
    }

    public function isAllowed(): bool
    {
        return null === $this->denial;
    }

    public function userId(): string
    {
        if (!$this->isAllowed() || null === $this->userId) {
            throw new LogicException('A denied activation decision has no authorized user.');
        }

        return $this->userId;
    }
}
