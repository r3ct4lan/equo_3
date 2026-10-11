<?php

declare(strict_types=1);

namespace App\Debts\Application\Port;

use DateTimeImmutable;

interface CursorPort
{
    /** @return array{before: ?DateTimeImmutable, id: ?string} */
    public function decode(?string $cursor): array;

    public function encode(DateTimeImmutable $createdAt, string $id): string;
}
