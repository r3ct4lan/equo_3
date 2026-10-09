<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Logout;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;

final readonly class LogoutAllSessions
{
    public function __construct(
        private IdentityRepositoryPort $identityRepository,
        private UserSessionRepositoryPort $sessionRepository,
        private TransactionPort $transaction,
        private ClockPort $clock,
    ) {
    }

    public function handle(string $currentUserId): void
    {
        $this->transaction->run(function () use ($currentUserId): void {
            $user = $this->identityRepository->currentUserForUpdate($currentUserId);

            if (null === $user || !$user->isActive) {
                throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
            }

            $this->sessionRepository->revokeAllUnfinishedForUser($user->id, $this->clock->now());
        });
    }
}
