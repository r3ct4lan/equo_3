<?php

declare(strict_types=1);

namespace App\Currency\Domain;

final readonly class FeedPolicy
{
    public function __construct(
        public int $expiresAt = 0,
        public int $lifetime = 0,
        public ?string $etag = null,
        public ?string $lastModified = null,
        public int $retryAt = 0,
    ) {
    }
}
