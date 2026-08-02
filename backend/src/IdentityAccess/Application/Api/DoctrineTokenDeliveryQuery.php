<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use DateTimeImmutable;

final readonly class DoctrineTokenDeliveryQuery implements TokenDeliveryQuery
{
    public function __construct(private IdentityRepositoryPort $repository)
    {
    }

    public function state(string $tokenId, DateTimeImmutable $now): ?TokenDeliveryState
    {
        return $this->repository->tokenDeliveryState($tokenId);
    }
}
