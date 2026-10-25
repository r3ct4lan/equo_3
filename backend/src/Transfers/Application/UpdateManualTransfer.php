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

final readonly class UpdateManualTransfer
{
    public function __construct(private TransferRepositoryPort $repository, private TransferRuntimePort $runtime, private AccountAccess $accounts, private ConnectAccess $connects, private TransferQueries $queries)
    {
    }

    /** @return array<string,mixed> */
    public function handle(UpdateManualTransferCommand $command, string $actorId): array
    {
        return $this->runtime->run(function () use ($command, $actorId): array {
            $transfer = $this->repository->find($command->transferId, true);
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
                $next = $transfer->reviseManual($command->senderId, $command->receiverId, $command->amount, $actorId, $command->expectedVersion, $sidesActive);
            } catch (ManualTransferRejected $e) {
                throw ManualTransferFailureMapper::map($e, $command->expectedVersion, $transfer->version);
            }
            if ($next !== $transfer) {
                $this->repository->updateManual($next);
            }

            return $this->queries->detail($next->id, $actorId);
        });
    }
}
