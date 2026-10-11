<?php

declare(strict_types=1);

namespace App\Connects\Application\Port;

use App\Connects\Domain\CanonicalPair;
use App\Connects\Domain\Connect;
use DateTimeImmutable;

interface ConnectRepositoryPort
{
    public function exists(CanonicalPair $pair): bool;

    public function find(string $id): ?Connect;

    /** @return array{connect: Connect, created: bool} */
    public function getOrCreate(Connect $candidate): array;

    /** @return list<Connect> */
    public function page(string $userId, int $limit, ?DateTimeImmutable $before, ?string $beforeId): array;
}
