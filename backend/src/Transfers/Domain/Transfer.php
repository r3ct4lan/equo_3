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

    public function assertConnectSides(string $first, string $second): void
    {
        if ($first === $second || !in_array($this->senderId, [$first, $second], true) || !in_array($this->receiverId, [$first, $second], true)) {
            throw new InvalidArgumentException('Transfer must connect exactly the two sides.');
        }
    }
}
