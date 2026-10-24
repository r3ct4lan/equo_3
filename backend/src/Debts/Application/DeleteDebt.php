<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Debts\Application\Port\DebtRepositoryPort;
use App\Debts\Application\Port\DebtRuntimePort;
use App\Debts\Domain\DebtUpdateFailure;
use App\Debts\Domain\DebtUpdateRejected;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\Api\AutomaticShares;

final readonly class DeleteDebt
{
    public function __construct(private DebtRepositoryPort $repository, private DebtRuntimePort $runtime, private AccountAccess $accounts, private AutomaticShares $shares)
    {
    }

    public function handle(string $debtId, int $expectedVersion, string $actorId): void
    {
        $this->runtime->run(function () use ($debtId, $expectedVersion, $actorId): void {
            $debt = $this->repository->find($debtId, true);
            if (null === $debt || !$debt->accessibleTo($actorId)) {
                throw new ApplicationFailure(ApplicationFailureCode::DebtNotAvailable);
            }
            if ($debt->createdById !== $actorId) {
                throw new ApplicationFailure(ApplicationFailureCode::Forbidden);
            }
            $active = [];
            foreach (array_unique([$debt->createdById, $debt->payerId]) as $id) {
                $account = $this->accounts->find($id, true);
                $active[$id] = null !== $account && $account->isActive;
            }
            try {
                $removed = $debt->remove($actorId, $expectedVersion, $active);
            } catch (DebtUpdateRejected $e) {
                $code = match ($e->reason) {
                    DebtUpdateFailure::Forbidden => ApplicationFailureCode::Forbidden,
                    DebtUpdateFailure::Deleted => ApplicationFailureCode::DebtDeleted,
                    DebtUpdateFailure::Frozen => ApplicationFailureCode::DebtFrozen,
                    DebtUpdateFailure::VersionConflict => ApplicationFailureCode::VersionConflict,
                    DebtUpdateFailure::InvalidParticipant, DebtUpdateFailure::InactiveParticipant => throw $e,
                };
                throw new ApplicationFailure($code, details: DebtUpdateFailure::VersionConflict === $e->reason ? ['expectedVersion' => $expectedVersion, 'actualVersion' => $debt->version] : null);
            }
            $this->repository->update($removed);
            $this->shares->removeForDebt($removed->id);
        });
    }
}
