<?php

declare(strict_types=1);

namespace App\Debts\Application\Port;

interface ReceiptStorage
{
    /** @param list<string> $images */
    public function prepare(string $owner, string $key, array $images): string;

    /** @return list<string> */
    public function draftPhotos(string $id, string $owner): array;

    public function attach(string $id, string $owner, string $debt): void;

    /** @return list<int> */
    public function photoNumbers(string $debt): array;
}
