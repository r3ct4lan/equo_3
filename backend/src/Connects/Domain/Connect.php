<?php

declare(strict_types=1);

namespace App\Connects\Domain;

use DateTimeImmutable;

final readonly class Connect
{
    public function __construct(public string $id, public CanonicalPair $pair, public DateTimeImmutable $createdAt)
    {
    }
}
