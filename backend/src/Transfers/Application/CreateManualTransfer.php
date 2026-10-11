<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\Port\TransferIdempotencyPort;
use App\Transfers\Application\Port\TransferRepositoryPort;
use App\Transfers\Application\Port\TransferRuntimePort;
use App\Transfers\Domain\Transfer;

final readonly class CreateManualTransfer
{
    public function __construct(private TransferRepositoryPort $repository, private TransferRuntimePort $runtime, private TransferIdempotencyPort $idempotency, private AccountAccess $accounts, private ConnectAccess $connects, private TransferQueries $queries)
    {
    }

    /** @return array{status:int,body:array<string,mixed>} */
    public function handle(CreateManualTransferCommand $command, string $authorId): array
    {
        if (!$this->connects->isSide($command->connectId, $authorId)) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }

        return $this->runtime->run(function () use ($command, $authorId): array {
            $scope = 'user:'.$authorId;
            $operation = 'transfer.create';
            $stored = $this->idempotency->begin($scope, $operation, $command->idempotencyKey, $command->canonical(), $this->runtime->now());
            if (null !== $stored) {
                $author = $this->accounts->find($authorId, true);
                if (null === $author || !$author->isActive) {
                    throw new ApplicationFailure(ApplicationFailureCode::InactiveUser);
                }

                return $stored;
            }
            if (!$this->connects->isPair($command->connectId, $command->senderId, $command->receiverId) || !in_array($authorId, [$command->senderId, $command->receiverId], true)) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
            $ids = [$command->senderId, $command->receiverId];
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $account = $this->accounts->find($id, true);
                if (null === $account || !$account->isActive) {
                    throw new ApplicationFailure(ApplicationFailureCode::InactiveUser);
                }
            }
            $transfer = Transfer::manual($this->runtime->newId(), $command->connectId, $command->senderId, $command->receiverId, $command->amount, $authorId, $this->runtime->now());
            $this->repository->save($transfer);
            $body = $this->queries->detail($transfer->id, $authorId);
            $this->idempotency->complete($scope, $operation, $command->idempotencyKey, 201, $body, $authorId);

            return ['status' => 201, 'body' => $body];
        });
    }
}
