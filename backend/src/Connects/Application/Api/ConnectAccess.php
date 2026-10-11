<?php

declare(strict_types=1);

namespace App\Connects\Application\Api;

interface ConnectAccess
{
    public function exists(string $firstUserId, string $secondUserId): bool;

    public function ensurePair(string $firstUserId, string $secondUserId): string;

    public function isPair(string $connectId, string $firstUserId, string $secondUserId): bool;

    /** @return array{view: array<string, mixed>, created: bool} */
    public function getOrCreate(string $firstUserId, string $secondUserId, string $viewerId): array;
}
