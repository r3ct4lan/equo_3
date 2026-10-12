<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\Debts\Application\Port\DebtRepositoryPort;
use App\Debts\Application\Port\DebtRuntimePort;
use App\Debts\Domain\DebtParticipant;
use App\Debts\Domain\DebtUpdateFailure;
use App\Debts\Domain\DebtUpdateRejected;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\Api\AutomaticShares;

final readonly class UpdateDebt
{
    public function __construct(private DebtRepositoryPort $repository, private DebtRuntimePort $runtime, private AccountAccess $accounts, private ConnectAccess $connects, private AutomaticShares $shares, private DebtViews $views)
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
            try {
                $next = $debt->revise($command->changes, $actorId, $command->expectedVersion, $active, $eligible, $this->runtime->newId(...), $this->runtime->now());
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
                $this->shares->recalculate($next->id, $next->payerId, array_map(static fn (DebtParticipant $p): array => ['participantId' => $p->id, 'userId' => $p->userId, 'isDeleted' => $p->isDeleted], $next->participants), $next->shareAmount(), $this->runtime->now());
            }

            return $this->views->detail($next, $actorId);
        });
    }
}
