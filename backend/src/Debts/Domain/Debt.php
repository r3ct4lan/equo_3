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
        $splitChanged = $update->receiptChanged || $this->totalAmount->minorUnits !== $update->totalAmount->minorUnits || $oldCurrent !== $ids;
        if (!$splitChanged) {
            if ($this->title === $update->title && $this->description === $update->description && $this->totalAmount == $update->totalAmount) {
                return $this;
            }

            return new self($this->id, $update->title, $update->description, $update->totalAmount, $this->payerId, $this->createdById, $this->createdAt, $this->participants, false, $this->version + 1);
        }
        $participants = [];
        $known = [];
        foreach ($this->participants as $p) {
            $known[$p->userId] = true;
            $participants[] = new DebtParticipant($p->id, $p->debtId, $p->userId, $p->createdAt, !in_array($p->userId, $ids, true), null === $update->amounts ? null : ($update->amounts[$p->userId] ?? 0));
        }
        foreach ($ids as $id) {
            if (!isset($known[$id])) {
                $participants[] = new DebtParticipant($newId(), $this->id, $id, $now, actualShareAmount: null === $update->amounts ? null : ($update->amounts[$id] ?? 0));
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

    public function shareFor(DebtParticipant $participant): int
    {
        return $participant->actualShareAmount ?? $this->shareAmount();
    }

    public function uniformShare(): ?int
    {
        $amounts = array_unique(array_map($this->shareFor(...), $this->currentParticipants()));

        return 1 === count($amounts) ? $amounts[0] : null;
    }

    public function hasSameSplitAs(self $other): bool
    {
        $before = $this->sharesByUser();
        $after = $other->sharesByUser();

        return $this->totalAmount->minorUnits === $other->totalAmount->minorUnits && $before === $after;
    }

    /** @return array<string,int> */
    private function sharesByUser(): array
    {
        $shares = [];
        foreach ($this->currentParticipants() as $participant) {
            $shares[$participant->userId] = $this->shareFor($participant);
        }
        ksort($shares, SORT_STRING);

        return $shares;
    }

    public function mergeAccounts(string $primary, string $joined): ?self
    {
        $byUser = array_column($this->participants, null, 'userId');
        if ($this->payerId !== $joined && $this->createdById !== $joined && !isset($byUser[$joined])) {
            return $this;
        }

        $payer = $this->payerId === $joined ? $primary : $this->payerId;
        $author = $this->createdById === $joined ? $primary : $this->createdById;
        $amounts = [];
        foreach ($this->currentParticipants() as $participant) {
            $user = $participant->userId === $joined ? $primary : $participant->userId;
            $amounts[$user] = ($amounts[$user] ?? 0) + $this->shareFor($participant);
        }
        if ([] === array_diff_key($amounts, [$payer => true])) {
            return null;
        }

        $participants = [];
        foreach ($this->participants as $participant) {
            if ($participant->userId === $joined && isset($byUser[$primary])) {
                $participants[] = new DebtParticipant($participant->id, $this->id, $joined, $participant->createdAt, true);
                continue;
            }
            $user = $participant->userId === $joined ? $primary : $participant->userId;
            $amount = $amounts[$user] ?? null;
            $participants[] = new DebtParticipant($participant->id, $this->id, $user, $participant->createdAt, null === $amount, $amount);
        }

        return new self($this->id, $this->title, $this->description, $this->totalAmount, $payer, $author, $this->createdAt, $participants, $this->isDeleted, $this->version + 1);
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
