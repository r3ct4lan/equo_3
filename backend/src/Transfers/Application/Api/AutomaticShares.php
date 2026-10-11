<?php

declare(strict_types=1);

namespace App\Transfers\Application\Api;

use DateTimeImmutable;

interface AutomaticShares
{
    public function create(string $connectId, string $payerId, string $receiverId, int $amount, string $debtId, string $participantId, DateTimeImmutable $createdAt): string;

    /** @return array<string,string> Participant ID to Transfer ID */
    public function forDebt(string $debtId): array;
}
