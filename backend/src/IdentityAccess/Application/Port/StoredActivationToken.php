<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Application\Authorization\ActivationTokenAccess;

final readonly class StoredActivationToken
{
    public function __construct(
        public string $tokenId,
        public ActivationTokenAccess $access,
        public bool $userActive,
    ) {
    }
}
