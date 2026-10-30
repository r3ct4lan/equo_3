<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\Debts\Application\Port\DebtIdempotencyPort;
use App\Debts\Application\Port\DebtRepositoryPort;
use App\Debts\Application\Port\DebtRuntimePort;
use App\Debts\Domain\Debt;
use App\Debts\Domain\DebtParticipant;
use App\Debts\Domain\Money;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\Api\AutomaticShares;

final readonly class CreateDebt
{
    public function __construct(private DebtRepositoryPort $repository, private DebtRuntimePort $runtime, private DebtIdempotencyPort $idempotency, private AccountAccess $accounts, private ConnectAccess $connects, private AutomaticShares $shares, private DebtViews $views)
    {
    }

    /** @return array{status:int,body:array<string,mixed>} */
    public function handle(CreateDebtCommand $command, string $authorId): array
    {
        if ($authorId !== $command->payerId && !in_array($authorId, $command->participantIds, true)) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidParticipant);
        }

        return $this->runtime->run(function () use ($command, $authorId): array {
            $scope = 'user:'.$authorId;
            $operation = 'debt.create';
            $stored = $this->idempotency->begin($scope, $operation, $command->idempotencyKey, $command->canonical(), $this->runtime->now());
            if (null !== $stored) {
                $a = $this->accounts->find($authorId, true);
                if (null === $a || !$a->isActive) {
                    throw new ApplicationFailure(ApplicationFailureCode::InactiveUser);
                }

                return $stored;
            }
            $ids = array_values(array_unique([$authorId, $command->payerId, ...$command->participantIds]));
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $account = $this->accounts->find($id, true);
                $eligible = $id === $authorId || $this->connects->exists($authorId, $id) || $this->repository->isCoParticipant($authorId, $id);
                if (!$eligible || null === $account) {
                    throw new ApplicationFailure(ApplicationFailureCode::InvalidParticipant);
                }
                if (!$account->isActive) {
                    throw new ApplicationFailure(ApplicationFailureCode::InactiveUser);
                }
            }
            $now = $this->runtime->now();
            $debtId = $this->runtime->newId();
            $participants = [];
            $users = $command->participantIds;
            if ($command->includePayerInSplit) {
                $users[] = $command->payerId;
            }
            foreach ($users as $id) {
                $participants[] = new DebtParticipant($this->runtime->newId(), $debtId, $id, $now);
            }
            $debt = new Debt($debtId, $command->title, $command->description, new Money($command->totalAmount), $command->payerId, $authorId, $now, $participants);
            $this->repository->save($debt);
            $participantIds = array_map(static fn (DebtParticipant $p): string => $p->userId, $participants);
            sort($participantIds, SORT_STRING);
            foreach ($participantIds as $i => $first) {
                foreach (array_slice($participantIds, $i + 1) as $second) {
                    $this->connects->ensurePair($first, $second);
                }
            }
            foreach ($participants as $p) {
                if ($p->userId === $debt->payerId) {
                    continue;
                }
                $connectId = $this->connects->ensurePair($debt->payerId, $p->userId);
                $this->shares->create($connectId, $debt->payerId, $p->userId, $debt->shareAmount(), $debtId, $p->id, $now);
            }
            $body = $this->views->detail($debt, $authorId);
            $this->idempotency->complete($scope, $operation, $command->idempotencyKey, 201, $body, $authorId);

            return ['status' => 201, 'body' => $body];
        });
    }
}
