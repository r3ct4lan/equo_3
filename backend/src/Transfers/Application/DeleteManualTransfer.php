<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\Port\TransferRepositoryPort;
use App\Transfers\Application\Port\TransferRuntimePort;
use App\Transfers\Domain\ManualTransferRejected;

final readonly class DeleteManualTransfer
{
    public function __construct(private TransferRepositoryPort $repository, private TransferRuntimePort $runtime, private AccountAccess $accounts, private ConnectAccess $connects)
    {
    }

    public function handle(string $transferId, int $expectedVersion, string $actorId): void
    {
        $this->runtime->run(function () use ($transferId, $expectedVersion, $actorId): void {
            $transfer = $this->repository->find($transferId, true);
            if (null === $transfer || !$this->connects->isSide($transfer->connectId, $actorId)) {
                throw new ApplicationFailure(ApplicationFailureCode::TransferNotAvailable);
            }
            $sidesActive = true;
            $ids = [$transfer->senderId, $transfer->receiverId];
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $account = $this->accounts->find($id, true);
                $sidesActive = $sidesActive && null !== $account && $account->isActive;
            }
            try {
                $removed = $transfer->removeManual($actorId, $expectedVersion, $sidesActive);
            } catch (ManualTransferRejected $e) {
                throw ManualTransferFailureMapper::map($e, $expectedVersion, $transfer->version);
            }
            $this->repository->updateManual($removed);
        });
    }
}
