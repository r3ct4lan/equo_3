<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Domain\Access\UserActionToken;
use DateTimeImmutable;

interface PasswordResetRepositoryPort
{
    public function accountForUpdate(string $normalizedEmail): ?PasswordResetAccount;

    public function replaceToken(UserActionToken $token, DateTimeImmutable $now): void;

    public function userIdForTokenHash(string $tokenHash): ?string;

    /** User must already be locked, before this token lock. */
    public function tokenForUpdate(string $tokenHash): ?StoredPasswordResetToken;

    public function completeReset(string $tokenId, string $userId, string $passwordHash, DateTimeImmutable $now): void;
}
