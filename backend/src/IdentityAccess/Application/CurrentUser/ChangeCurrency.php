<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\CurrentUser;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Api\UserView;
use App\IdentityAccess\Application\Port\CurrentUserProfileRepositoryPort;
use App\IdentityAccess\Application\Port\TransactionPort;

final readonly class ChangeCurrency
{
    public function __construct(private CurrentUserProfileRepositoryPort $repository, private TransactionPort $transaction, private \App\Currency\Application\ExchangeMarket $market)
    {
    }

    public function handle(string $userId, string $currency): UserView
    {
        $this->market->requireCurrency($currency);

        return $this->transaction->run(function () use ($userId, $currency): UserView {
            $state = $this->repository->currentUserForUpdate($userId);
            if (null === $state || !$state->isActive) {
                throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
            }
            $this->repository->changeCurrency($userId, $currency);

            return $this->repository->currentUserProfile($userId)
                ?? throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
        });
    }
}
