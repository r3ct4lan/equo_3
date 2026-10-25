<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AccountDeactivation;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\AccountDeactivationRepositoryPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;

final readonly class DeactivateAccount
{
    public function __construct(
        private AccountDeactivationRepositoryPort $repository,
        private UserSessionRepositoryPort $sessions,
        private PasswordHashingPort $hasher,
        private TransactionPort $transaction,
        private ClockPort $clock,
    ) {
    }

    public function handle(DeactivateAccountCommand $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $account = $this->repository->accountForUpdate($command->userId);
            if (null === $account || !$account->isActive) {
                throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
            }
            if (!$account->verifyPassword($this->hasher, $command->currentPassword)) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidCredentials);
            }

            $this->repository->deactivate($account->id);
            $this->sessions->revokeAllUnfinishedForUser($account->id, $this->clock->now());
        });
    }
}
