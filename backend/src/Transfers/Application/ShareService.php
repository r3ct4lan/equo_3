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
use RuntimeException;

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

    public function recalculate(string $debtId, string $payerId, array $participants, int $amount, DateTimeImmutable $now): void
    {
        $existing = $this->repository->sharesForDebt($debtId);
        foreach ($participants as $p) {
            if ($p['userId'] === $payerId) {
                continue;
            }
            $id = $existing[$p['participantId']] ?? null;
            if (null !== $id) {
                $old = $this->repository->find($id);
                if (null === $old) {
                    throw new RuntimeException('Automatic share is missing.');
                }
                $next = $old->recalculateShare($p['isDeleted'] ? $old->amount : $amount, $p['isDeleted']);
                if ($next !== $old) {
                    $this->repository->updateShare($next);
                }
            } elseif (!$p['isDeleted']) {
                $connect = $this->connects->ensurePair($payerId, $p['userId']);
                $this->create($connect, $payerId, $p['userId'], $amount, $debtId, $p['participantId'], $now);
            }
        }
    }

    public function removeForDebt(string $debtId): void
    {
        foreach ($this->repository->sharesForDebt($debtId) as $id) {
            $old = $this->repository->find($id);
            if (null === $old) {
                throw new RuntimeException('Automatic share is missing.');
            }
            $next = $old->recalculateShare($old->amount, true);
            if ($next !== $old) {
                $this->repository->updateShare($next);
            }
        }
    }

    public function forDebt(string $debtId): array
    {
        return $this->repository->sharesForDebt($debtId);
    }
}
