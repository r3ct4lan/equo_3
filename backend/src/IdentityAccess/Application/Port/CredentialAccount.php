<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

final readonly class CredentialAccount
{
    public function __construct(
        public string $id,
        public ?int $telegramId,
        public bool $active,
        public string $locale,
        public ?StoredLoginIdentity $password,
    ) {
    }
}
