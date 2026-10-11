<?php

declare(strict_types=1);

namespace App\Debts\Application\Port;

use DateTimeImmutable;

interface DebtRuntimePort
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;

    public function now(): DateTimeImmutable;

    public function newId(): string;
}
