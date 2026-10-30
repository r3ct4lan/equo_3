<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\CurrentUser;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\UserView;
use App\IdentityAccess\Application\CurrentUser\ChangeLocale;
use App\IdentityAccess\Application\Port\CurrentUserProfileRepositoryPort;
use App\IdentityAccess\Application\Port\CurrentUserState;
use App\IdentityAccess\Application\Port\TransactionPort;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ChangeLocaleTest extends TestCase
{
    public function testChangesLocaleForActiveUser(): void
    {
        $repository = $this->createMock(CurrentUserProfileRepositoryPort::class);
        $repository->expects(self::once())->method('currentUserForUpdate')->with('user')->willReturn(new CurrentUserState('user', true));
        $repository->expects(self::once())->method('changeLocale')->with('user', 'ru');
        $profile = new UserView('user', 'Name', 'a@example.test', true, new DateTimeImmutable(), 'ru');
        $repository->method('currentUserProfile')->willReturn($profile);
        $transaction = $this->createStub(TransactionPort::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $callback) => $callback());

        self::assertSame('ru', (new ChangeLocale($repository, $transaction))->handle('user', 'ru')->locale);
    }

    public function testRejectsUnsupportedLocale(): void
    {
        $repository = $this->createStub(CurrentUserProfileRepositoryPort::class);
        $transaction = $this->createMock(TransactionPort::class);
        $transaction->expects(self::never())->method('run');
        $this->expectException(ApplicationFailure::class);
        (new ChangeLocale($repository, $transaction))->handle('user', 'fr');
    }

    public function testRejectsInactiveUser(): void
    {
        $repository = $this->createMock(CurrentUserProfileRepositoryPort::class);
        $repository->method('currentUserForUpdate')->willReturn(new CurrentUserState('user', false));
        $repository->expects(self::never())->method('changeLocale');
        $transaction = $this->createStub(TransactionPort::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $callback) => $callback());
        $this->expectException(ApplicationFailure::class);
        (new ChangeLocale($repository, $transaction))->handle('user', 'ru');
    }
}
