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

    /**
     * @param array<string,bool> $activeUsers
     * @param array<string,bool> $eligibleUsers
     * @param callable():string  $newId
     */
    public function revise(DebtUpdate $update, string $actorId, int $expectedVersion, array $activeUsers, array $eligibleUsers, callable $newId, DateTimeImmutable $now): self
    {
        if ($actorId !== $this->createdById) {
            throw new DebtUpdateRejected(DebtUpdateFailure::Forbidden);
        }
        if ($this->isDeleted) {
            throw new DebtUpdateRejected(DebtUpdateFailure::Deleted);
        }
        if (!($activeUsers[$this->createdById] ?? false) || !($activeUsers[$this->payerId] ?? false)) {
            throw new DebtUpdateRejected(DebtUpdateFailure::Frozen);
        }
        if ($expectedVersion !== $this->version) {
            throw new DebtUpdateRejected(DebtUpdateFailure::VersionConflict);
        }
        $ids = $update->participantIds;
        if ([] === $ids || count(array_unique($ids)) !== count($ids) || in_array($this->payerId, $ids, true) || ($this->createdById !== $this->payerId && !in_array($this->createdById, $ids, true))) {
            throw new DebtUpdateRejected(DebtUpdateFailure::InvalidParticipant);
        }
        if ($update->includePayerInSplit) {
            $ids[] = $this->payerId;
        }
        sort($ids, SORT_STRING);
        $oldCurrent = array_map(static fn (DebtParticipant $p): string => $p->userId, $this->currentParticipants());
        foreach ($ids as $id) {
            if (in_array($id, $oldCurrent, true)) {
                continue;
            }
            if (!($eligibleUsers[$id] ?? false)) {
                throw new DebtUpdateRejected(DebtUpdateFailure::InvalidParticipant);
            }
            if (!($activeUsers[$id] ?? false)) {
                throw new DebtUpdateRejected(DebtUpdateFailure::InactiveParticipant);
            }
        }
        sort($oldCurrent, SORT_STRING);
        if ($this->title === $update->title && $this->description === $update->description && $this->totalAmount->cents === $update->totalAmount->cents && $oldCurrent === $ids) {
            return $this;
        }
        $participants = [];
        $known = [];
        foreach ($this->participants as $p) {
            $known[$p->userId] = true;
            $participants[] = new DebtParticipant($p->id, $p->debtId, $p->userId, $p->createdAt, !in_array($p->userId, $ids, true));
        }
        foreach ($ids as $id) {
            if (!isset($known[$id])) {
                $participants[] = new DebtParticipant($newId(), $this->id, $id, $now);
            }
        }

        return new self($this->id, $update->title, $update->description, $update->totalAmount, $this->payerId, $this->createdById, $this->createdAt, $participants, false, $this->version + 1);
    }

    /** @param array<string,bool> $activeUsers */
    public function remove(string $actorId, int $expectedVersion, array $activeUsers): self
    {
        if ($actorId !== $this->createdById) {
            throw new DebtUpdateRejected(DebtUpdateFailure::Forbidden);
        }
        if ($this->isDeleted) {
            throw new DebtUpdateRejected(DebtUpdateFailure::Deleted);
        }
        if (!($activeUsers[$this->createdById] ?? false) || !($activeUsers[$this->payerId] ?? false)) {
            throw new DebtUpdateRejected(DebtUpdateFailure::Frozen);
        }
        if ($expectedVersion !== $this->version) {
            throw new DebtUpdateRejected(DebtUpdateFailure::VersionConflict);
        }

        return new self($this->id, $this->title, $this->description, $this->totalAmount, $this->payerId, $this->createdById, $this->createdAt, $this->participants, true, $this->version + 1);
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
