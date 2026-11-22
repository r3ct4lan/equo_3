<?php

declare(strict_types=1);

namespace App\Debts\Application\Port;

use App\Debts\Domain\Debt;

interface MergeExpenseRepositoryPort
{
    /** @return list<string> */
    public function expenseIdsFor(string $joined): array;

    public function hide(Debt $expense): void;

    public function replace(Debt $previous, Debt $merged, string $primary, string $joined): void;
}
