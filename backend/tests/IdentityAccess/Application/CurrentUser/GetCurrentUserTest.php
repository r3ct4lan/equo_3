<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\CurrentUser;

use App\IdentityAccess\Application\Api\TokenDeliveryState;
use App\IdentityAccess\Application\Api\UserView;
use App\IdentityAccess\Application\CurrentUser\GetCurrentUser;
use App\IdentityAccess\Application\Port\CurrentUserState;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\StoredActivationToken;
use App\IdentityAccess\Application\Port\StoredLoginIdentity;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\User\User;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class GetCurrentUserTest extends TestCase
{
    public function testReturnsActiveCurrentUserProfile(): void
    {
        $user = new UserView(
            '87000000-0000-4000-8000-000000000001',
            'Current User',
            'current@example.test',
            true,
            new DateTimeImmutable('2026-08-08T12:00:00Z'),
        );

        self::assertSame($user, (new GetCurrentUser(new CurrentUserRepository($user)))->handle($user->id));
    }

    public function testUnknownInactiveAndMismatchedUserAreRejected(): void
    {
        self::assertNull((new GetCurrentUser(new CurrentUserRepository(null)))->handle('87000000-0000-4000-8000-000000000002'));
        self::assertNull((new GetCurrentUser(new CurrentUserRepository(new UserView(
            '87000000-0000-4000-8000-000000000003',
            'Inactive User',
            'inactive@example.test',
            false,
            new DateTimeImmutable('2026-08-08T12:00:00Z'),
        ))))->handle('87000000-0000-4000-8000-000000000003'));
        self::assertNull((new GetCurrentUser(new CurrentUserRepository(new UserView(
            '87000000-0000-4000-8000-000000000004',
            'Other User',
            'other@example.test',
            true,
            new DateTimeImmutable('2026-08-08T12:00:00Z'),
        ))))->handle('87000000-0000-4000-8000-000000000005'));
    }

    public function testResultContainsOnlyPublicProfileFields(): void
    {
        $user = new UserView(
            '87000000-0000-4000-8000-000000000006',
            'Public User',
            'public@example.test',
            true,
            new DateTimeImmutable('2026-08-08T12:00:00Z'),
        );

        $result = (new GetCurrentUser(new CurrentUserRepository($user)))->handle($user->id);
        self::assertNotNull($result);
        self::assertSame(['createdAt', 'email', 'id', 'isActive', 'name'], $this->sortedKeys($result->toArray()));
        self::assertStringNotContainsString('password', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $values
     * @return list<string>
     */
    private function sortedKeys(array $values): array
    {
        $keys = array_keys($values);
        sort($keys);

        return $keys;
    }
}

final readonly class CurrentUserRepository implements IdentityRepositoryPort
{
    public function __construct(private ?UserView $user)
    {
    }

    public function currentUserProfile(string $userId): ?UserView
    {
        return $this->user;
    }

    public function emailExists(string $normalizedEmail): bool
    {
        throw new LogicException('Password/login lookup must not be used by current-user query.');
    }

    public function loginIdentityByEmail(string $normalizedEmail): ?StoredLoginIdentity
    {
        throw new LogicException('Password hash must not be queried by current-user query.');
    }

    public function currentUserState(string $userId): ?CurrentUserState
    {
        throw new LogicException('Current-user query must read the public profile.');
    }

    public function addRegistration(User $user, UserActionToken $token): void
    {
        throw new LogicException('Current-user query is read-only.');
    }

    public function activationTokenForUpdate(string $tokenHash): ?StoredActivationToken
    {
        throw new LogicException('Current-user query must not inspect action tokens.');
    }

    public function activate(string $tokenId, string $userId, DateTimeImmutable $usedAt): void
    {
        throw new LogicException('Current-user query is read-only.');
    }

    public function tokenDeliveryState(string $tokenId): ?TokenDeliveryState
    {
        throw new LogicException('Current-user query must not inspect action tokens.');
    }
}
