<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Application\CurrentUser\GetCurrentUser;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CurrentUserPersistenceTest extends KernelTestCase
{
    private Connection $connection;
    private PasswordHashingPort $passwordHasher;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->passwordHasher = self::getContainer()->get(PasswordHashingPort::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testCurrentUserLookupReturnsFreshActiveProfile(): void
    {
        $userId = '87000000-0000-4000-8000-000000000201';
        $this->insertUser($userId, 'Original Name', 'original@example.test', true);

        $this->connection->executeStatement('UPDATE app_user SET name = ?, email = ? WHERE id = ?', [
            'Updated Name',
            'updated@example.test',
            $userId,
        ]);

        $profile = self::getContainer()->get(GetCurrentUser::class)->handle($userId);

        self::assertNotNull($profile);
        self::assertSame($userId, $profile->id);
        self::assertSame('Updated Name', $profile->name);
        self::assertSame('updated@example.test', $profile->email);
        self::assertTrue($profile->isActive);
        self::assertSame('2026-08-08T12:00:00Z', $profile->toArray()['createdAt']);
        self::assertStringNotContainsString('password', json_encode($profile->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testUnknownAndInactiveUsersAreRejected(): void
    {
        $inactiveId = '87000000-0000-4000-8000-000000000202';
        $this->insertUser($inactiveId, 'Inactive Name', 'inactive-current@example.test', false);

        self::assertNull(self::getContainer()->get(GetCurrentUser::class)->handle('87000000-0000-4000-8000-000000000299'));
        self::assertNull(self::getContainer()->get(GetCurrentUser::class)->handle($inactiveId));
    }

    public function testAccessTokenDoesNotDependOnRefreshSessionStateForActiveUser(): void
    {
        $userId = '87000000-0000-4000-8000-000000000203';
        $this->insertUser($userId, 'Sessionless User', 'sessionless@example.test', true);

        $profile = self::getContainer()->get(GetCurrentUser::class)->handle($userId);

        self::assertNotNull($profile);
        self::assertSame($userId, $profile->id);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id = ?', [$userId]));
    }

    private function insertUser(string $id, string $name, string $email, bool $active): void
    {
        $this->connection->insert('app_user', [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'password_hash' => $this->passwordHasher->hash('Correct password!'),
            'is_active' => $active ? 1 : 0,
            'created_at' => (new DateTimeImmutable('2026-08-08T12:00:00Z'))->format('Y-m-d H:i:sP'),
        ]);
    }
}
