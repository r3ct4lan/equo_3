<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Domain\Access\UserSession;

interface UserSessionRepositoryPort
{
    public function add(UserSession $session): void;

    /**
     * Must be called inside TransactionPort. Implementations lock the matched row for atomic refresh or revoke.
     */
    public function findByIdForUpdate(string $sessionId): ?UserSession;

    public function save(UserSession $session): void;
}
