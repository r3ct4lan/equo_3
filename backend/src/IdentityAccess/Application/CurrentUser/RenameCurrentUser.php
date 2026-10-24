<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\CurrentUser;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Api\UserView;
use App\IdentityAccess\Application\Port\CurrentUserProfileRepositoryPort;
use App\IdentityAccess\Application\Port\TransactionPort;

final readonly class RenameCurrentUser
{
    public function __construct(
        private CurrentUserProfileRepositoryPort $repository,
        private TransactionPort $transaction,
    ) {
    }

    public function handle(string $userId, string $name): UserView
    {
        return $this->transaction->run(function () use ($userId, $name): UserView {
            $state = $this->repository->currentUserForUpdate($userId);
            if (null === $state || !$state->isActive) {
                throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
            }

            $this->repository->renameCurrentUser($userId, $name);
            $profile = $this->repository->currentUserProfile($userId);
            if (null === $profile) {
                throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
            }

            return $profile;
        });
    }
}
