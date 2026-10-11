<?php

declare(strict_types=1);

namespace App\Transfers\Application\Port;

use App\Transfers\Domain\Transfer;

interface TransferRepositoryPort
{
    public function save(Transfer $transfer): void;

    /** @return array<string,string> */
    public function sharesForDebt(string $debtId): array;
}
