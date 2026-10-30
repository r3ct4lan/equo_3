<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\CurrentUser;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Api\UserView;
use App\IdentityAccess\Application\Port\CurrentUserProfileRepositoryPort;
use App\IdentityAccess\Application\Port\TransactionPort;

final readonly class ChangeLocale
{
    public function __construct(private CurrentUserProfileRepositoryPort $repository, private TransactionPort $transaction)
    {
    }

    public function handle(string $userId, string $locale): UserView
    {
        if (!in_array($locale, ['en', 'ru'], true)) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }

        return $this->transaction->run(function () use ($userId, $locale): UserView {
            $state = $this->repository->currentUserForUpdate($userId);
            if (null === $state || !$state->isActive) {
                throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
            }
            $this->repository->changeLocale($userId, $locale);

            return $this->repository->currentUserProfile($userId)
                ?? throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
        });
    }
}
