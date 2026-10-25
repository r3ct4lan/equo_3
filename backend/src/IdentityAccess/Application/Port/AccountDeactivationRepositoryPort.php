<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface AccountDeactivationRepositoryPort
{
    /** Fresh credential under User lock; must precede session locks. */
    public function accountForUpdate(string $userId): ?StoredLoginIdentity;

    /** The caller must already hold the User lock in this transaction. */
    public function deactivate(string $userId): void;
}
