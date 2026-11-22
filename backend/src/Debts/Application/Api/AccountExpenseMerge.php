<?php

declare(strict_types=1);

namespace App\Debts\Application\Api;

interface AccountExpenseMerge
{
    /** Apply within the account merge transaction. */
    public function apply(string $primary, string $joined): void;
}
