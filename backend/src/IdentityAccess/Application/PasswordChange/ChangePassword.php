<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\PasswordChange;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\PasswordChangeRepositoryPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Domain\User\PasswordPolicy;
use App\IdentityAccess\Domain\User\PasswordPolicyViolation;

final readonly class ChangePassword
{
    public function __construct(
        private PasswordChangeRepositoryPort $repository,
        private UserSessionRepositoryPort $sessions,
        private PasswordPolicy $policy,
        private PasswordHashingPort $hasher,
        private TransactionPort $transaction,
        private ClockPort $clock,
    ) {
    }

    public function handle(ChangePasswordCommand $command): void
    {
        try {
            $this->policy->assertSatisfied($command->newPassword);
        } catch (PasswordPolicyViolation) {
            throw new ApplicationFailure(ApplicationFailureCode::PasswordPolicyViolation);
        }

        $this->transaction->run(function () use ($command): void {
            $account = $this->repository->accountForUpdate($command->userId);
            if (null === $account || !$account->isActive) {
                throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
            }
            if (!$account->verifyPassword($this->hasher, $command->currentPassword)) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidCredentials);
            }
            $this->repository->replacePasswordHash($account->id, $this->hasher->hash($command->newPassword));
            $this->sessions->revokeAllUnfinishedForUser($account->id, $this->clock->now());
        });
    }
}
