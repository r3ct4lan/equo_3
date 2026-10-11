<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Transfers\Application\Api\BalanceQuery;
use App\Transfers\Application\Port\TransferRepositoryPort;

final readonly class BalanceService implements BalanceQuery
{
    public function __construct(private TransferRepositoryPort $repository)
    {
    }

    public function balance(string $connectId, string $viewerId): int
    {
        return $this->repository->balance($connectId, $viewerId);
    }
}
