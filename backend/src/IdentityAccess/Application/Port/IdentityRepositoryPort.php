<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Application\Api\TokenDeliveryState;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\User\User;
use DateTimeImmutable;

interface IdentityRepositoryPort
{
    public function emailExists(string $normalizedEmail): bool;

    public function loginIdentityByEmail(string $normalizedEmail): ?StoredLoginIdentity;

    public function currentUserState(string $userId): ?CurrentUserState;

    public function addRegistration(User $user, UserActionToken $token): void;

    public function activationTokenForUpdate(string $tokenHash): ?StoredActivationToken;

    public function activate(string $tokenId, string $userId, DateTimeImmutable $usedAt): void;

    public function tokenDeliveryState(string $tokenId): ?TokenDeliveryState;
}
