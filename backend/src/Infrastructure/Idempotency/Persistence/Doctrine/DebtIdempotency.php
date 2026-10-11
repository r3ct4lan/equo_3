<?php

declare(strict_types=1);

namespace App\Infrastructure\Idempotency\Persistence\Doctrine;

use App\Debts\Application\Port\DebtIdempotencyPort;
use DateTimeImmutable;

final readonly class DebtIdempotency implements DebtIdempotencyPort
{
    public function __construct(private DoctrineIdempotency $idempotency)
    {
    }

    public function begin(string $scope, string $operation, string $key, string $canonicalRequest, DateTimeImmutable $now): ?array
    {
        $stored = $this->idempotency->begin($scope, $operation, $key, $canonicalRequest, $now);

        return null === $stored ? null : ['status' => $stored->status, 'body' => $stored->body];
    }

    public function complete(string $scope, string $operation, string $key, int $status, array $body, ?string $userId): void
    {
        $this->idempotency->complete($scope, $operation, $key, $status, $body, $userId);
    }
}
