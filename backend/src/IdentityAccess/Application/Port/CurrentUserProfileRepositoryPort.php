<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Application\Api\UserView;

interface CurrentUserProfileRepositoryPort
{
    /** Must run inside TransactionPort. */
    public function currentUserForUpdate(string $userId): ?CurrentUserState;

    public function currentUserProfile(string $userId): ?UserView;

    public function renameCurrentUser(string $userId, string $name): void;

    public function changeLocale(string $userId, string $locale): void;
}
