<?php

declare(strict_types=1);

namespace App\Transfers\Application\Api;

interface BalanceQuery
{
    public function balance(string $connectId, string $viewerId): int;
}
