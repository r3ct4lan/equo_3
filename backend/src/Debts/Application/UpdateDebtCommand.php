<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Debts\Domain\DebtUpdate;
use App\Debts\Domain\Money;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;

final readonly class UpdateDebtCommand
{
    public string $debtId;
    public DebtUpdate $changes;

    /** @param list<string> $participantIds */
    public function __construct(string $debtId, string $title, ?string $description, int $amount, array $participantIds, bool $includePayerInSplit, public int $expectedVersion)
    {
        if (!CreateDebtCommand::isUuid($debtId) || '' === trim($title) || $amount < 1 || $amount > Money::MAX_CENTS || $expectedVersion < 1 || $expectedVersion > 2147483647) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
        foreach ($participantIds as $id) {
            if (!CreateDebtCommand::isUuid($id)) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
        }
        $this->debtId = strtolower($debtId);
        $ids = array_map(strtolower(...), $participantIds);
        sort($ids, SORT_STRING);
        $this->changes = new DebtUpdate($title, $description, new Money($amount), $ids, $includePayerInSplit);
    }
}
