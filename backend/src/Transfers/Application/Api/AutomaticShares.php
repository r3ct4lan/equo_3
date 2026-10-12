<?php

declare(strict_types=1);

namespace App\Transfers\Application\Api;

use DateTimeImmutable;

interface AutomaticShares
{
    public function create(string $connectId, string $payerId, string $receiverId, int $amount, string $debtId, string $participantId, DateTimeImmutable $createdAt): string;

    /** @param list<array{participantId:string,userId:string,isDeleted:bool}> $participants */
    public function recalculate(string $debtId, string $payerId, array $participants, int $amount, DateTimeImmutable $now): void;

    /** @return array<string,string> Participant ID to Transfer ID */
    public function forDebt(string $debtId): array;
}
