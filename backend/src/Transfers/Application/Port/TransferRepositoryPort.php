<?php

declare(strict_types=1);

namespace App\Transfers\Application\Port;

use App\Transfers\Domain\Transfer;
use DateTimeImmutable;

interface TransferRepositoryPort
{
    public function find(string $id, bool $forUpdate = false): ?Transfer;

    /** @return list<Transfer> */
    public function page(string $connectId, int $limit, ?DateTimeImmutable $before, ?string $beforeId): array;

    /** @return list<Transfer> */
    public function pageForUser(string $userId, string $direction, int $limit, ?DateTimeImmutable $before, ?string $beforeId): array;

    /** @return iterable<array{isShare:bool,connectId:string,senderId:string,amount:int,currency:string,manualRate:?string,rateCurrency:?string}> */
    public function openAmounts(string $viewerId, ?string $connectId): iterable;

    /** @return array<string,array{id:string,remaining:int}> */
    public function shareBalances(string $debtId): array;

    public function availableBefore(string $paymentId, string $shareId): int;

    public function save(Transfer $transfer): void;

    public function updateShare(Transfer $transfer): void;

    public function updateManual(Transfer $transfer): void;

    /** @return array<string,string> */
    public function sharesForDebt(string $debtId): array;
}
