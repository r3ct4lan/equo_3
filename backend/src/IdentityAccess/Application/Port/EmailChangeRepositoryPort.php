<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Domain\Access\UserActionToken;
use DateTimeImmutable;

interface EmailChangeRepositoryPort
{
    public function accountForUpdate(string $userId): ?StoredLoginIdentity;

    public function emailExists(string $normalizedEmail): bool;

    /** @param array{newEmail: string} $payload */
    public function replaceToken(UserActionToken $token, array $payload, DateTimeImmutable $now): void;

    public function userIdForTokenHash(string $tokenHash): ?string;

    public function tokenForUpdate(string $tokenHash): ?StoredEmailChangeToken;

    public function completeChange(string $tokenId, string $userId, string $newEmail, DateTimeImmutable $now): void;
}
