<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\User;

final readonly class VerifiedTelegramIdentity
{
    public function __construct(
        public int $id,
        public string $name,
        public string $locale = 'en',
        public ?string $startParam = null,
    ) {
    }
}
