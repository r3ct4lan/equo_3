<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\Currency\Application\ExchangeMarket;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\Port\TransferRepositoryPort;
use App\Transfers\Application\Port\TransferRuntimePort;
use App\Transfers\Domain\ManualTransferRejected;

final readonly class UpdateManualTransfer
{
    public function __construct(private TransferRepositoryPort $repository, private TransferRuntimePort $runtime, private AccountAccess $accounts, private ConnectAccess $connects, private TransferQueries $queries, private ExchangeMarket $market)
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
                $relatedId = null === $command->relatedTransferId ? null : strtolower($command->relatedTransferId);
                $targetId = $command->relatedSpecified ? $relatedId : $transfer->relatedTransferId;
                $target = null === $targetId ? null : $this->repository->find($targetId, true);
                if (null !== $command->currency && $transfer->currency !== $command->currency) {
                    throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
                }
                $targetCurrency = $target->currency ?? $transfer->rateCurrency ?? $this->accounts->find($actorId)?->currency ?? 'EUR';
                $this->market->validateManualRate($transfer->currency, $targetCurrency, $command->manualRate);
                $next = $transfer->reviseManual($command->senderId, $command->receiverId, $command->amount, $actorId, $command->expectedVersion, $sidesActive, $command->relatedSpecified, $relatedId, $command->manualRate, null === $command->manualRate ? null : $targetCurrency, $command->rateSpecified);
            } catch (ManualTransferRejected $e) {
                throw ManualTransferFailureMapper::map($e, $command->expectedVersion, $transfer->version);
            }
            if ($command->relatedSpecified && null !== $relatedId && $relatedId !== $transfer->relatedTransferId) {
                $target = $this->repository->find($relatedId, true);
                if (null === $target || 'DEBT_SHARE' !== $target->type || $target->isDeleted || $target->connectId !== $transfer->connectId) {
                    throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
                }
            }
            if ($next !== $transfer) {
                $this->repository->updateManual($next);
            }

            return $this->queries->detail($next->id, $actorId);
        });
    }
}
