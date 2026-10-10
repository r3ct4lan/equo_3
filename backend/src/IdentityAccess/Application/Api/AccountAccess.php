<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

interface AccountAccess
{
    public function find(string $id, bool $forUpdate = false): ?AccountSummary;
}
