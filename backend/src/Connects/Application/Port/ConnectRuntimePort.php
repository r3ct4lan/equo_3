<?php

declare(strict_types=1);

namespace App\Connects\Application\Port;

use DateTimeImmutable;

interface ConnectRuntimePort
{
    public function now(): DateTimeImmutable;

    public function newId(): string;

    public function run(callable $operation): mixed;
}
