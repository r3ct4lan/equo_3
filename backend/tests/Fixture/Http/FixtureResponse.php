<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Http;

use DateTimeImmutable;

final readonly class FixtureResponse
{
    /** @param list<string> $items */
    public function __construct(
        public string $id,
        public DateTimeImmutable $createdAt,
        public FixtureStatus $status,
        public FixtureNestedResponse $nested,
        public ?string $optional = null,
        public array $items = [],
    ) {
    }
}
