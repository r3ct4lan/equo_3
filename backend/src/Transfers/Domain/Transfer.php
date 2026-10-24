<?php

declare(strict_types=1);

namespace App\Transfers\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Transfer
{
    public function __construct(public string $id, public string $connectId, public string $senderId, public string $receiverId, public int $amount, public string $type, public ?string $createdById, public DateTimeImmutable $createdAt, public ?string $debtId = null, public ?string $debtParticipantId = null, public ?string $relatedTransferId = null, public bool $isDeleted = false, public int $version = 1)
    {
        if ($amount < 1 || $amount > 2147483647 || $senderId === $receiverId || $version < 1) {
            throw new InvalidArgumentException('Transfer fields are invalid.');
        }
        if ('DEBT_SHARE' === $type) {
            if (null !== $createdById || null === $debtId || null === $debtParticipantId || null !== $relatedTransferId) {
                throw new InvalidArgumentException('Automatic transfer links are invalid.');
            }
        } elseif ('MANUAL' === $type) {
            if (!in_array($createdById, [$senderId, $receiverId], true) || null !== $debtId || null !== $debtParticipantId) {
                throw new InvalidArgumentException('Manual transfer links are invalid.');
            }
        } else {
            throw new InvalidArgumentException('Transfer type is invalid.');
        }
    }

    public static function manual(string $id, string $connectId, string $senderId, string $receiverId, int $amount, string $authorId, DateTimeImmutable $createdAt): self
    {
        return new self($id, $connectId, $senderId, $receiverId, $amount, 'MANUAL', $authorId, $createdAt);
    }

    public function recalculateShare(int $amount, bool $isDeleted): self
    {
        if ('DEBT_SHARE' !== $this->type) {
            throw new InvalidArgumentException('Only automatic shares can be recalculated.');
        }
        if ($amount === $this->amount && $isDeleted === $this->isDeleted) {
            return $this;
        }

        return new self($this->id, $this->connectId, $this->senderId, $this->receiverId, $amount, $this->type, null, $this->createdAt, $this->debtId, $this->debtParticipantId, null, $isDeleted, $this->version + 1);
    }

    public function reviseManual(string $senderId, string $receiverId, int $amount, string $actorId, int $expectedVersion, bool $sidesActive): self
    {
        $this->assertManualChangeAllowed($actorId, $expectedVersion, $sidesActive);
        $oldSides = [$this->senderId, $this->receiverId];
        $newSides = [$senderId, $receiverId];
        sort($oldSides, SORT_STRING);
        sort($newSides, SORT_STRING);
        if ($oldSides !== $newSides || $senderId === $receiverId) {
            throw new ManualTransferRejected(ManualTransferFailure::InvalidSides);
        }
        if ($this->senderId === $senderId && $this->receiverId === $receiverId && $this->amount === $amount) {
            return $this;
        }

        return new self($this->id, $this->connectId, $senderId, $receiverId, $amount, $this->type, $this->createdById, $this->createdAt, null, null, $this->relatedTransferId, false, $this->version + 1);
    }

    public function removeManual(string $actorId, int $expectedVersion, bool $sidesActive): self
    {
        $this->assertManualChangeAllowed($actorId, $expectedVersion, $sidesActive);

        return new self($this->id, $this->connectId, $this->senderId, $this->receiverId, $this->amount, $this->type, $this->createdById, $this->createdAt, null, null, $this->relatedTransferId, true, $this->version + 1);
    }

    private function assertManualChangeAllowed(string $actorId, int $expectedVersion, bool $sidesActive): void
    {
        if ('MANUAL' !== $this->type) {
            throw new ManualTransferRejected(ManualTransferFailure::Automatic);
        }
        if ($actorId !== $this->createdById) {
            throw new ManualTransferRejected(ManualTransferFailure::Forbidden);
        }
        if ($this->isDeleted) {
            throw new ManualTransferRejected(ManualTransferFailure::Deleted);
        }
        if (!$sidesActive) {
            throw new ManualTransferRejected(ManualTransferFailure::Frozen);
        }
        if ($expectedVersion !== $this->version) {
            throw new ManualTransferRejected(ManualTransferFailure::VersionConflict);
        }
    }

    public function assertConnectSides(string $first, string $second): void
    {
        if ($first === $second || !in_array($this->senderId, [$first, $second], true) || !in_array($this->receiverId, [$first, $second], true)) {
            throw new InvalidArgumentException('Transfer must connect exactly the two sides.');
        }
    }
}
