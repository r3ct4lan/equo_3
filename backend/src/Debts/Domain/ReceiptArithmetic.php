<?php

declare(strict_types=1);

namespace App\Debts\Domain;

interface ReceiptArithmetic
{
    /** @param list<array{int,int,int}> $portions Line amount, assigned units and total units */
    public function discountedShare(array $portions, int $total, int $gross): int;
}
