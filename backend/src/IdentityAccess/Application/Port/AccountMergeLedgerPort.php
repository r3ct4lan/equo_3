<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use DateTimeImmutable;

interface AccountMergeLedgerPort
{
    /** Apply within the account merge transaction, after the expense shares are merged. */
    public function apply(string $primary, string $joined, DateTimeImmutable $now): void;
}
