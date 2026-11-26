<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\Currency\Application\Api\ExchangeMarket;
use App\Debts\Application\Port\DebtRepositoryPort;
use App\Debts\Application\Port\DebtRuntimePort;
use App\Debts\Application\Port\ReceiptStorage;
use App\Debts\Domain\DebtParticipant;
use App\Debts\Domain\DebtUpdate;
use App\Debts\Domain\DebtUpdateFailure;
use App\Debts\Domain\DebtUpdateRejected;
use App\Debts\Domain\Money;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\Api\AutomaticShares;

final readonly class UpdateDebt
{
    public function __construct(private DebtRepositoryPort $repository, private DebtRuntimePort $runtime, private AccountAccess $accounts, private ConnectAccess $connects, private AutomaticShares $shares, private DebtViews $views, private ExchangeMarket $market, private ReceiptAllocation $allocation, private ReceiptStorage $receipts)
    {
    }

    /** @return array<string,mixed> */
    public function handle(UpdateDebtCommand $command, string $actorId): array
    {
        return $this->runtime->run(function () use ($command, $actorId): array {
            $debt = $this->repository->find($command->debtId, true);
            if (null === $debt || !$debt->accessibleTo($actorId)) {
                throw new ApplicationFailure(ApplicationFailureCode::DebtNotAvailable);
            }
            if ($debt->createdById !== $actorId) {
                throw new ApplicationFailure(ApplicationFailureCode::Forbidden);
            }
            $current = array_map(static fn (DebtParticipant $p): string => $p->userId, $debt->currentParticipants());
            $ids = array_values(array_unique([$debt->createdById, $debt->payerId, ...$current, ...$command->changes->participantIds]));
            sort($ids, SORT_STRING);
            $active = [];
            $eligible = [];
            foreach ($ids as $id) {
                $account = $this->accounts->find($id, true);
                $active[$id] = null !== $account && $account->isActive;
                $eligible[$id] = null !== $account && ($id === $actorId || in_array($id, $current, true) || $this->connects->exists($actorId, $id) || $this->repository->isCoParticipant($actorId, $id));
            }
            $old = $debt->totalAmount;
            if (null !== $command->currency && $command->currency !== $old->currency) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
            $manual = $command->rateSpecified ? $command->manualRate : $old->manualRate;
            $target = $old->rateCurrency ?? $this->accounts->find($actorId)->currency ?? 'EUR';
            $this->market->validateManualRate($old->currency, $target, $manual);
            $change = $command->changes;
            $money = new Money($change->totalAmount->minorUnits, $old->currency, $manual, null === $manual ? null : $target);
            $saved = $this->receipts->saved($debt->id);
            $receipt = $command->receipt;
            if ((null === $saved) !== (null === $receipt) || ($saved['uploadId'] ?? null) !== ($receipt['uploadId'] ?? null)) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
            $participants = $change->participantIds;
            $includePayer = $change->includePayerInSplit;
            $amounts = null;
            $receiptChanged = false;
            if (null !== $receipt) {
                $amounts = $this->allocation->shares($receipt, $money->minorUnits, $includePayer ? [...$participants, $debt->payerId] : $participants, $saved);
                $participants = array_values(array_filter($participants, static fn (string $id): bool => $id === $actorId || isset($amounts[$id])));
                $includePayer = $includePayer && ($debt->payerId === $actorId || isset($amounts[$debt->payerId]));
                $receiptChanged = self::ordered($receipt) !== self::ordered($saved ?? []);
                if ($receiptChanged && null !== $saved && $old->minorUnits === $money->minorUnits) {
                    $previousAmounts = $this->allocation->shares($saved, $old->minorUnits, $current, $saved);
                    if (self::ordered($previousAmounts) === self::ordered($amounts)) {
                        $amounts = [];
                        foreach ($debt->currentParticipants() as $previous) {
                            $amounts[$previous->userId] = $debt->shareFor($previous);
                        }
                    }
                }
            }
            $changes = new DebtUpdate($change->title, $change->description, $money, $participants, $includePayer, $amounts, $receiptChanged);
            try {
                $next = $debt->revise($changes, $actorId, $command->expectedVersion, $active, $eligible, $this->runtime->newId(...), $this->runtime->now());
            } catch (DebtUpdateRejected $e) {
                $code = match ($e->reason) {
                    DebtUpdateFailure::Forbidden => ApplicationFailureCode::Forbidden,
                    DebtUpdateFailure::Deleted => ApplicationFailureCode::DebtDeleted,
                    DebtUpdateFailure::Frozen => ApplicationFailureCode::DebtFrozen,
                    DebtUpdateFailure::VersionConflict => ApplicationFailureCode::VersionConflict,
                    DebtUpdateFailure::InvalidParticipant => ApplicationFailureCode::InvalidParticipant,
                    DebtUpdateFailure::InactiveParticipant => ApplicationFailureCode::InactiveUser,
                };
                throw new ApplicationFailure($code, details: DebtUpdateFailure::VersionConflict === $e->reason ? ['expectedVersion' => $command->expectedVersion, 'actualVersion' => $debt->version] : null);
            }
            if ($next !== $debt) {
                $this->repository->update($next);
                if ($receiptChanged && null !== $receipt) {
                    $this->receipts->revise($next->id, $receipt);
                }
            }
            if (!$next->hasSameSplitAs($debt)) {
                $nextIds = array_map(static fn (DebtParticipant $p): string => $p->userId, $next->currentParticipants());
                sort($nextIds, SORT_STRING);
                $added = array_diff($nextIds, $current);
                foreach ($nextIds as $i => $first) {
                    foreach (array_slice($nextIds, $i + 1) as $second) {
                        if (in_array($first, $added, true) || in_array($second, $added, true)) {
                            $this->connects->ensurePair($first, $second);
                        }
                    }
                }
                $this->shares->recalculate($next->id, $next->payerId, array_map(static fn (DebtParticipant $p): array => ['participantId' => $p->id, 'userId' => $p->userId, 'isDeleted' => $p->isDeleted, 'amount' => $next->shareFor($p)], $next->participants), $next->shareAmount(), $this->runtime->now(), $next->totalAmount->currency);
            }

            return $this->views->detail($next, $actorId);
        });
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    private static function ordered(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn (mixed $v): mixed => is_array($v) ? self::ordered($v) : $v, $value);
    }
}
