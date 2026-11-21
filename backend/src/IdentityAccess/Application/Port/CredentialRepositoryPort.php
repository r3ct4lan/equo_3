<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;

interface CredentialRepositoryPort
{
    public function accountForUpdate(string $userId): ?CredentialAccount;

    public function activeSession(string $userId, string $sessionId, DateTimeImmutable $now): bool;

    public function telegramOwner(int $telegramId): ?string;

    public function setTelegram(string $userId, int $telegramId): void;

    public function setEmail(string $userId, string $email, string $passwordHash): void;

    public function revokeOtherSessions(string $userId, ?string $preservedSession, DateTimeImmutable $now): void;

    /** @param array<string, mixed> $payload */
    public function replaceAction(UserActionToken $action, array $payload, DateTimeImmutable $now): void;

    public function useAction(string $id, DateTimeImmutable $now): void;

    public function currentAction(string $userId, string $sessionId, UserActionTokenPurpose $purpose): ?StoredEmailChangeToken;

    public function cancelActions(string $userId, string $sessionId, DateTimeImmutable $now): void;
}
