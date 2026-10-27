<?php

declare(strict_types=1);

namespace App\Infrastructure\Application\Api;

interface RequestLimiter
{
    /** @param array<string, int> $limits Keys identify independent quotas; values are their maximum request counts. */
    public function consume(array $limits): ?int;
}
