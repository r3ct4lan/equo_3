<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface TransactionPort
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;
}
