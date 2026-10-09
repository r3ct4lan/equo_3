<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\Logout;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Logout\LogoutAllSessions;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CurrentUserState;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;

final class LogoutAllSessionsTest extends TestCase
{
    public function testLocksAndRechecksOwnerInsideTransactionBeforeBulkRevokeEvenForZeroRows(): void
    {
        $now = new DateTimeImmutable('2026-10-09T12:00:00Z');
        $state = new stdClass();
        $state->insideTransaction = false;
        $state->ownerLocked = false;
        $identities = $this->createMock(IdentityRepositoryPort::class);
        $identities->expects(self::once())->method('currentUserForUpdate')->with('user')->willReturnCallback(
            static function () use ($state): CurrentUserState {
                self::assertTrue($state->insideTransaction);
                $state->ownerLocked = true;

                return new CurrentUserState('user', true);
            },
        );
        $sessions = $this->createMock(UserSessionRepositoryPort::class);
        $sessions->expects(self::once())->method('revokeAllUnfinishedForUser')->with('user', $now)->willReturnCallback(
            static function () use ($state): int {
                self::assertTrue($state->insideTransaction);
                self::assertTrue($state->ownerLocked);

                return 0;
            },
        );
        $transaction = $this->createMock(TransactionPort::class);
        $transaction->expects(self::once())->method('run')->willReturnCallback(
            static function (callable $operation) use ($state): mixed {
                $state->insideTransaction = true;

                return $operation();
            },
        );
        $clock = $this->createMock(ClockPort::class);
        $clock->expects(self::once())->method('now')->willReturn($now);

        (new LogoutAllSessions($identities, $sessions, $transaction, $clock))->handle('user');
    }

    public function testUnknownOrInactiveOwnerCannotRevokeSessions(): void
    {
        foreach ([null, new CurrentUserState('user', false)] as $owner) {
            $identities = $this->createStub(IdentityRepositoryPort::class);
            $identities->method('currentUserForUpdate')->willReturn($owner);
            $sessions = $this->createMock(UserSessionRepositoryPort::class);
            $sessions->expects(self::never())->method('revokeAllUnfinishedForUser');
            $transaction = $this->createStub(TransactionPort::class);
            $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());

            try {
                (new LogoutAllSessions($identities, $sessions, $transaction, $this->createStub(ClockPort::class)))->handle('user');
                self::fail('Inactive or unknown owner must fail.');
            } catch (ApplicationFailure $failure) {
                self::assertSame(ApplicationFailureCode::AuthenticationRequired, $failure->failureCode);
            }
        }
    }
}
