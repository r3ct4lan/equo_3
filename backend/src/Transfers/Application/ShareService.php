<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\Transfers\Application\Api\AutomaticShares;
use App\Transfers\Application\Port\TransferRepositoryPort;
use App\Transfers\Application\Port\TransferRuntimePort;
use App\Transfers\Domain\Transfer;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ShareService implements AutomaticShares
{
    public function __construct(private TransferRepositoryPort $repository, private TransferRuntimePort $runtime, private ConnectAccess $connects)
    {
    }

    public function create(string $connectId, string $payerId, string $receiverId, int $amount, string $debtId, string $participantId, DateTimeImmutable $createdAt): string
    {
        if (!$this->connects->isPair($connectId, $payerId, $receiverId)) {
            throw new InvalidArgumentException('Share Connect does not match its sides.');
        }
        $t = new Transfer($this->runtime->newId(), $connectId, $payerId, $receiverId, $amount, 'DEBT_SHARE', null, $createdAt, $debtId, $participantId);
        $t->assertConnectSides($payerId, $receiverId);
        $this->repository->save($t);

        return $t->id;
    }

    public function forDebt(string $debtId): array
    {
        return $this->repository->sharesForDebt($debtId);
    }
}
