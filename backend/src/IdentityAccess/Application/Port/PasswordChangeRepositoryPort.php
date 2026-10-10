<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface PasswordChangeRepositoryPort
{
    /** Fresh credential under User lock; must precede session locks. */
    public function accountForUpdate(string $userId): ?StoredLoginIdentity;

    /** The caller must already hold the User lock in this transaction. */
    public function replacePasswordHash(string $userId, string $passwordHash): void;
}
