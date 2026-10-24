<?php

declare(strict_types=1);

namespace App\Connects\Application;

use App\Connects\Application\Port\ConnectIdempotencyPort;
use App\Connects\Application\Port\ConnectRuntimePort;
use App\Debts\Application\Api\DebtAccess;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;

final readonly class CreateConnect
{
    public function __construct(
        private ConnectService $connects,
        private ConnectRuntimePort $runtime,
        private ConnectIdempotencyPort $idempotency,
        private AccountAccess $accounts,
        private DebtAccess $debts,
    ) {
    }

    /** @return array{status:int, body:array<string,mixed>} */
    public function handle(CreateConnectCommand $command, string $authorId): array
    {
        if ($authorId === $command->otherUserId) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }

        return $this->runtime->run(function () use ($command, $authorId): array {
            $scope = 'user:'.$authorId;
            $operation = 'connect.create';
            $stored = $this->idempotency->begin($scope, $operation, $command->idempotencyKey, $command->canonical(), $this->runtime->now());
            if (null !== $stored) {
                $author = $this->accounts->find($authorId, true);
                if (null === $author || !$author->isActive) {
                    throw new ApplicationFailure(ApplicationFailureCode::InactiveUser);
                }

                return $stored;
            }

            if (!$this->debts->allowsManualConnect($command->sharedDebtId, $authorId, $command->otherUserId)) {
                throw new ApplicationFailure(ApplicationFailureCode::DebtNotAvailable);
            }
            $ids = [$authorId, $command->otherUserId];
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $account = $this->accounts->find($id, true);
                if (null === $account || !$account->isActive) {
                    throw new ApplicationFailure(ApplicationFailureCode::InactiveUser);
                }
            }

            $result = $this->connects->getOrCreate($authorId, $command->otherUserId, $authorId);
            $status = $result['created'] ? 201 : 200;
            $this->idempotency->complete($scope, $operation, $command->idempotencyKey, $status, $result['view'], $authorId);

            return ['status' => $status, 'body' => $result['view']];
        });
    }
}
