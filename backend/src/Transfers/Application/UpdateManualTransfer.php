<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\Currency\Application\Api\ExchangeMarket;
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
            $transfer = $this->repository->find($command->transferId);
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
            $transfer = $this->repository->find($command->transferId, true);
            if (null === $transfer || !$this->connects->isSide($transfer->connectId, $actorId)) {
                throw new ApplicationFailure(ApplicationFailureCode::TransferNotAvailable);
            }
            try {
                $transfer->assertManualChangeAllowed($actorId, $command->expectedVersion, $sidesActive);
                $relatedId = null === $command->relatedTransferId ? null : strtolower($command->relatedTransferId);
                $targetId = $command->relatedSpecified ? $relatedId : $transfer->relatedTransferId;
                $target = null === $targetId ? null : $this->repository->find($targetId, true);
                if (null !== $command->currency && $transfer->currency !== $command->currency) {
                    throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
                }
                $relinked = $targetId !== $transfer->relatedTransferId;
                if (null !== $targetId && (null === $target || 'DEBT_SHARE' !== $target->type || $target->connectId !== $transfer->connectId
                    || ($relinked && $target->isDeleted) || (!$target->isDeleted && ($target->senderId !== $command->receiverId || $target->receiverId !== $command->senderId)))) {
                    throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
                }
                $profileCurrency = $this->accounts->find($actorId)?->currency ?? 'EUR';
                $targetCurrency = $target->currency ?? ($relinked ? $profileCurrency : ($transfer->rateCurrency ?? $profileCurrency));
                $manualRate = $command->rateSpecified ? $command->manualRate : ($targetCurrency === $transfer->rateCurrency ? $transfer->manualRate : null);
                $this->market->validateManualRate($transfer->currency, $targetCurrency, $manualRate);
                $settlementRate = $transfer->settlementRate;
                if (null === $target) {
                    $settlementRate = null;
                } elseif ($relinked || $manualRate !== $transfer->manualRate) {
                    $settlementRate = $manualRate ?? $this->market->quote($transfer->currency, $targetCurrency);
                }
                $next = $transfer->reviseManual($command->senderId, $command->receiverId, $command->amount, $actorId, $command->expectedVersion, $sidesActive, $command->relatedSpecified, $relatedId, $manualRate, null === $manualRate ? null : $targetCurrency, true, $settlementRate);
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
