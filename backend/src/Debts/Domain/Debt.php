<?php

declare(strict_types=1);

namespace App\Debts\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Debt
{
    /** @param list<DebtParticipant> $participants */
    public function __construct(public string $id, public string $title, public ?string $description, public Money $totalAmount, public string $payerId, public string $createdById, public DateTimeImmutable $createdAt, public array $participants, public bool $isDeleted = false, public int $version = 1)
    {
        if ('' === trim($title) || $version < 1) {
            throw new InvalidArgumentException('Debt fields are invalid.');
        }
        $ids = [];
        $users = [];
        foreach ($participants as $p) {
            if ($p->debtId !== $id || isset($ids[$p->id]) || isset($users[$p->userId])) {
                throw new InvalidArgumentException('Debt participants must be unique and belong to this debt.');
            }
            $ids[$p->id] = true;
            $users[$p->userId] = true;
        }
        $current = array_map(static fn (DebtParticipant $p): string => $p->userId, $this->currentParticipants());
        if ([] === array_diff($current, [$payerId]) || ($createdById !== $payerId && !in_array($createdById, $current, true))) {
            throw new InvalidArgumentException('Debt needs another participant and must include its author.');
        }
    }

    /** @return list<DebtParticipant> */
    public function currentParticipants(): array
    {
        return array_values(array_filter($this->participants, static fn (DebtParticipant $p): bool => !$p->isDeleted));
    }

    public function includePayerInSplit(): bool
    {
        foreach ($this->currentParticipants() as $p) {
            if ($p->userId === $this->payerId) {
                return true;
            }
        }

        return false;
    }

    public function shareAmount(): int
    {
        return $this->totalAmount->share(count($this->currentParticipants()));
    }

    public function accessibleTo(string $userId): bool
    {
        if ($userId === $this->createdById || $userId === $this->payerId) {
            return true;
        }
        foreach ($this->currentParticipants() as $p) {
            if ($p->userId === $userId) {
                return true;
            }
        }

        return false;
    }
}
