<?php

declare(strict_types=1);

namespace App\Transfers\Application\Port;

interface TransferRuntimePort
{
    public function newId(): string;
}
