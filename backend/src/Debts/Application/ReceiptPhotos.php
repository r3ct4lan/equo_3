<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Debts\Application\Port\DebtRepositoryPort;
use App\Debts\Application\Port\DebtRuntimePort;
use App\Debts\Application\Port\ReceiptStorage;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;

final readonly class ReceiptPhotos
{
    public function __construct(private DebtRuntimePort $runtime, private DebtRepositoryPort $debts, private ReceiptStorage $receipts, private AccountAccess $accounts)
    {
    }

    public function read(string $id, int $number, string $actor): string
    {
        return $this->runtime->run(function () use ($id, $number, $actor): string {
            $debt = CreateDebtCommand::isUuid($id) ? $this->debts->find($id, true) : null;
            if (null === $debt || !$debt->accessibleTo($actor)) {
                throw new ApplicationFailure(ApplicationFailureCode::DebtNotAvailable);
            }

            return $this->receipts->photo($id, $number) ?? throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        });
    }

    public function remove(string $id, int $number, string $actor, int $version): void
    {
        $this->runtime->run(function () use ($id, $number, $actor, $version): void {
            $debt = CreateDebtCommand::isUuid($id) ? $this->debts->find($id, true) : null;
            if (null === $debt || !$debt->accessibleTo($actor)) {
                throw new ApplicationFailure(ApplicationFailureCode::DebtNotAvailable);
            }
            if ($actor !== $debt->createdById) {
                throw new ApplicationFailure(ApplicationFailureCode::Forbidden);
            }
            if ($debt->isDeleted) {
                throw new ApplicationFailure(ApplicationFailureCode::DebtDeleted);
            }
            $ids = array_unique([$actor, $debt->payerId]);
            sort($ids, SORT_STRING);
            foreach ($ids as $person) {
                if (!$this->accounts->find($person, true)?->isActive) {
                    throw new ApplicationFailure(ApplicationFailureCode::DebtFrozen);
                }
            }
            if ($debt->version !== $version) {
                throw new ApplicationFailure(ApplicationFailureCode::VersionConflict, details: ['actualVersion' => $debt->version, 'expectedVersion' => $version]);
            }
            $this->receipts->removePhoto($id, $number);
        });
    }
}
