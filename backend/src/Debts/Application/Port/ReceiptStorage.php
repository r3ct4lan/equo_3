<?php

declare(strict_types=1);

namespace App\Debts\Application\Port;

interface ReceiptStorage
{
    /** @param list<string> $images */
    public function prepare(string $owner, string $key, array $images): string;

    /** @return list<string> */
    public function draftPhotos(string $id, string $owner): array;

    /** @param array<string,mixed> $allocation */
    public function attach(string $id, string $owner, string $debt, array $allocation = []): void;

    /** @return array<string,mixed>|null */
    public function saved(string $debt): ?array;

    /** @param array<string,mixed> $allocation */
    public function revise(string $debt, array $allocation): void;

    public function photo(string $debt, int $number): ?string;

    public function removePhoto(string $debt, int $number): void;

    /** @return list<int> */
    public function photoNumbers(string $debt): array;

    /** @return array{id:string,state:string,draft:?array<string,mixed>,photos:list<int>} */
    public function status(string $id, string $owner): array;

    public function retry(string $id, string $owner, string $attempt): void;

    /** @return list<string>|null */
    public function claim(string $id, string $owner, string $attempt): ?array;

    /** @param array<string,mixed>|null $draft */
    public function finish(string $id, string $attempt, ?array $draft): void;
}
