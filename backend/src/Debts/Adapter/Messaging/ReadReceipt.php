<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Messaging;

final readonly class ReadReceipt
{
    public function __construct(public string $uploadId, public string $owner, public string $attempt)
    {
    }
}
