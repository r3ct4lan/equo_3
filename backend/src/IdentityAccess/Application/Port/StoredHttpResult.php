<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

final readonly class StoredHttpResult
{
    /** @param array<string, mixed> $body */
    public function __construct(public int $status, public array $body)
    {
    }
}
