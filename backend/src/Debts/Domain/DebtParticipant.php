<?php

declare(strict_types=1);

namespace App\Debts\Domain;

use DateTimeImmutable;

final readonly class DebtParticipant
{
    public function __construct(public string $id, public string $debtId, public string $userId, public DateTimeImmutable $createdAt, public bool $isDeleted = false)
    {
    }
}
