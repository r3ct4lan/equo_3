<?php

declare(strict_types=1);

namespace App\Debts\Application\Port;

use App\Debts\Domain\Debt;

interface DebtRepositoryPort
{
    public function save(Debt $debt): void;

    public function find(string $id): ?Debt;

    public function isCoParticipant(string $authorId, string $candidateId): bool;
}
