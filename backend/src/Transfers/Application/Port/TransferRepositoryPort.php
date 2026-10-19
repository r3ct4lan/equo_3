<?php

declare(strict_types=1);

namespace App\Transfers\Application\Port;

use App\Transfers\Domain\Transfer;
use DateTimeImmutable;

interface TransferRepositoryPort
{
    public function find(string $id): ?Transfer;

    /** @return list<Transfer> */
    public function page(string $connectId, int $limit, ?DateTimeImmutable $before, ?string $beforeId): array;

    public function balance(string $connectId, string $viewerId): int;

    /** @return array{totalOwedAmount: int, totalReceivableAmount: int} */
    public function balanceSummary(string $viewerId): array;

    public function save(Transfer $transfer): void;

    public function updateShare(Transfer $transfer): void;

    /** @return array<string,string> */
    public function sharesForDebt(string $debtId): array;
}
