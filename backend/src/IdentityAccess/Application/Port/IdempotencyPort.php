<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use DateTimeImmutable;

interface IdempotencyPort
{
    public function begin(
        string $scope,
        string $operation,
        string $key,
        string $canonicalRequest,
        DateTimeImmutable $now,
    ): ?StoredHttpResult;

    /** @param array<string, mixed> $body */
    public function complete(
        string $scope,
        string $operation,
        string $key,
        int $status,
        array $body,
        ?string $userId,
    ): void;
}
