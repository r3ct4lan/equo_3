<?php

declare(strict_types=1);

namespace App\Connects\Application\Port;

use DateTimeImmutable;

interface ConnectIdempotencyPort
{
    /** @return array{status:int, body:array<string,mixed>}|null */
    public function begin(string $scope, string $operation, string $key, string $canonicalRequest, DateTimeImmutable $now): ?array;

    /** @param array<string,mixed> $body */
    public function complete(string $scope, string $operation, string $key, int $status, array $body, ?string $userId): void;
}
