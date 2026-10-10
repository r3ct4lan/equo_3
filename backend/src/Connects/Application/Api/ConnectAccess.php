<?php

declare(strict_types=1);

namespace App\Connects\Application\Api;

interface ConnectAccess
{
    /** @return array{view: array<string, mixed>, created: bool} */
    public function getOrCreate(string $firstUserId, string $secondUserId, string $viewerId): array;
}
