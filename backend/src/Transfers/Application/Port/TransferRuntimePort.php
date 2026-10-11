<?php

declare(strict_types=1);

namespace App\Transfers\Application\Port;

use DateTimeImmutable;

interface TransferRuntimePort
{
    /** @template T
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;

    public function now(): DateTimeImmutable;

    public function newId(): string;
}
