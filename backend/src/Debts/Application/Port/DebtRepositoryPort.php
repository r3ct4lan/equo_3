<?php

declare(strict_types=1);

namespace App\Debts\Application\Port;

use App\Debts\Domain\Debt;
use DateTimeImmutable;

interface DebtRepositoryPort
{
    /** @return list<Debt> */
    public function page(string $viewerId, int $limit, ?DateTimeImmutable $before, ?string $beforeId): array;

    public function save(Debt $debt): void;

    public function find(string $id, bool $forUpdate = false): ?Debt;

    public function update(Debt $debt): void;

    public function isCoParticipant(string $authorId, string $candidateId): bool;
}
