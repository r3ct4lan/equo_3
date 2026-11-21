<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Debts\Application\Api\AccountExpenseMerge;
use App\Debts\Application\Port\DebtRepositoryPort;
use App\Debts\Application\Port\MergeExpenseRepositoryPort;

final readonly class MergeExpenses implements AccountExpenseMerge
{
    public function __construct(
        private DebtRepositoryPort $expenses,
        private MergeExpenseRepositoryPort $merge,
    ) {
    }

    public function apply(string $primary, string $joined): void
    {
        foreach ($this->merge->expenseIdsFor($joined) as $id) {
            $previous = $this->expenses->find($id, true);
            if (null === $previous) {
                continue;
            }
            $merged = $previous->mergeAccounts($primary, $joined);
            if (null === $merged) {
                $this->merge->hide($previous);
            } elseif ($merged !== $previous) {
                $this->merge->replace($previous, $merged, $primary, $joined);
            }
        }
    }
}
