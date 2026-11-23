<?php

declare(strict_types=1);

namespace App\Transfers\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Transfer
{
    public function __construct(public string $id, public string $connectId, public string $senderId, public string $receiverId, public int $amount, public string $type, public ?string $createdById, public DateTimeImmutable $createdAt, public ?string $debtId = null, public ?string $debtParticipantId = null, public ?string $relatedTransferId = null, public bool $isDeleted = false, public int $version = 1, public string $currency = 'EUR', public ?string $manualRate = null, public ?string $rateCurrency = null, public ?string $settlementRate = null, public int $creditedAmount = 0, public ?int $excessAmount = null)
    {
        if ($amount < 1 || $amount > 9007199254740991 || $senderId === $receiverId || $version < 1) {
            throw new InvalidArgumentException('Transfer fields are invalid.');
        }
        if ('DEBT_SHARE' === $type) {
            if (null !== $createdById || null === $debtId || null === $debtParticipantId || null !== $relatedTransferId) {
                throw new InvalidArgumentException('Automatic transfer links are invalid.');
            }
        } elseif ('MANUAL' === $type) {
            if ($amount > 2147483647 || !in_array($createdById, [$senderId, $receiverId], true) || null !== $debtId || null !== $debtParticipantId) {
                throw new InvalidArgumentException('Manual transfer links are invalid.');
            }
        } else {
            throw new InvalidArgumentException('Transfer type is invalid.');
        }
    }

    public static function manual(string $id, string $connectId, string $senderId, string $receiverId, int $amount, string $authorId, DateTimeImmutable $createdAt, ?string $relatedTransferId = null, string $currency = 'EUR', ?string $manualRate = null, ?string $rateCurrency = null, ?string $settlementRate = null): self
    {
        return new self($id, $connectId, $senderId, $receiverId, $amount, 'MANUAL', $authorId, $createdAt, relatedTransferId: $relatedTransferId, currency: $currency, manualRate: $manualRate, rateCurrency: $rateCurrency, settlementRate: $settlementRate);
    }

    public function recalculateShare(int $amount, bool $isDeleted): self
    {
        if ('DEBT_SHARE' !== $this->type) {
            throw new InvalidArgumentException('Only automatic shares can be recalculated.');
        }
        if ($amount === $this->amount && $isDeleted === $this->isDeleted) {
            return $this;
        }

        return new self($this->id, $this->connectId, $this->senderId, $this->receiverId, $amount, $this->type, null, $this->createdAt, $this->debtId, $this->debtParticipantId, null, $isDeleted, $this->version + 1, $this->currency, $this->manualRate, $this->rateCurrency);
    }

    public function reviseManual(string $senderId, string $receiverId, int $amount, string $actorId, int $expectedVersion, bool $sidesActive, bool $relatedSpecified = false, ?string $relatedTransferId = null, ?string $manualRate = null, ?string $rateCurrency = null, bool $changeRate = false, ?string $settlementRate = null): self
    {
        $this->assertManualChangeAllowed($actorId, $expectedVersion, $sidesActive);
        $oldSides = [$this->senderId, $this->receiverId];
        $newSides = [$senderId, $receiverId];
        sort($oldSides, SORT_STRING);
        sort($newSides, SORT_STRING);
        if ($oldSides !== $newSides || $senderId === $receiverId) {
            throw new ManualTransferRejected(ManualTransferFailure::InvalidSides);
        }
        $related = $relatedSpecified ? $relatedTransferId : $this->relatedTransferId;
        if (!$changeRate) {
            $manualRate = $this->manualRate;
            $rateCurrency = $this->rateCurrency;
        }
        if ($manualRate === $this->manualRate && $rateCurrency === $this->rateCurrency && $this->senderId === $senderId && $this->receiverId === $receiverId && $this->amount === $amount && $related === $this->relatedTransferId) {
            return $this;
        }

        return new self($this->id, $this->connectId, $senderId, $receiverId, $amount, $this->type, $this->createdById, $this->createdAt, null, null, $related, false, $this->version + 1, $this->currency, $manualRate, $rateCurrency, $settlementRate);
    }

    public function removeManual(string $actorId, int $expectedVersion, bool $sidesActive): self
    {
        $this->assertManualChangeAllowed($actorId, $expectedVersion, $sidesActive);

        return new self($this->id, $this->connectId, $this->senderId, $this->receiverId, $this->amount, $this->type, $this->createdById, $this->createdAt, null, null, $this->relatedTransferId, true, $this->version + 1, $this->currency, $this->manualRate, $this->rateCurrency, $this->settlementRate);
    }

    public function assertManualChangeAllowed(string $actorId, int $expectedVersion, bool $sidesActive): void
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
