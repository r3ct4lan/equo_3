<?php

declare(strict_types=1);

namespace App\Debts\Domain;

use DomainException;

final class DebtUpdateRejected extends DomainException
{
    public function __construct(public readonly DebtUpdateFailure $reason)
    {
        parent::__construct('Debt update is not allowed.');
    }
}
