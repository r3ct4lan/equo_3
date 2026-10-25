<?php

declare(strict_types=1);

namespace App\Transfers\Domain;

use DomainException;

final class ManualTransferRejected extends DomainException
{
    public function __construct(public readonly ManualTransferFailure $reason)
    {
        parent::__construct($reason->name);
    }
}
