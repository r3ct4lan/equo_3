<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\CurrentUser;

use App\IdentityAccess\Application\Api\UserView;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;

final readonly class GetCurrentUser
{
    public function __construct(private IdentityRepositoryPort $identityRepository)
    {
    }

    public function handle(string $userId): ?UserView
    {
        $user = $this->identityRepository->currentUserProfile($userId);

        if (null === $user || !$user->isActive || $user->id !== $userId) {
            return null;
        }

        return $user;
    }
}
