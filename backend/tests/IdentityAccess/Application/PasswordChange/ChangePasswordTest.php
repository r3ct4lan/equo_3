<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\PasswordChange;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\PasswordChange\ChangePassword;
use App\IdentityAccess\Application\PasswordChange\ChangePasswordCommand;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\PasswordChangeRepositoryPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\StoredLoginIdentity;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Domain\User\PasswordPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChangePasswordTest extends TestCase
{
    #[DataProvider('denials')]
    public function testDenialsNeverHashWriteOrRevoke(string $case, ApplicationFailureCode $code, string $newPassword): void
    {
        $repository = $this->createMock(PasswordChangeRepositoryPort::class);
        $repository->expects('policy' === $case ? self::never() : self::once())->method('accountForUpdate')->with('user')
            ->willReturn('missing' === $case ? null : new StoredLoginIdentity('user', 'Name', 'user@example.test', 'private-hash', 'inactive' !== $case));
        $repository->expects(self::never())->method('replacePasswordHash');
        $sessions = $this->createMock(UserSessionRepositoryPort::class);
        $sessions->expects(self::never())->method('revokeAllUnfinishedForUser');
        $hasher = $this->createMock(PasswordHashingPort::class);
        $hasher->expects('wrong' === $case ? self::once() : self::never())->method('verify')->willReturn(false);
        $hasher->expects(self::never())->method('hash');
        try {
            $this->handler($repository, $sessions, $hasher)->handle(new ChangePasswordCommand('user', 'old', $newPassword));
            self::fail('The command must be denied.');
        } catch (ApplicationFailure $failure) {
            self::assertSame($code, $failure->failureCode);
        }
    }

    /** @return iterable<string, array{string, ApplicationFailureCode, string}> */
    public static function denials(): iterable
    {
        foreach (['short', '12345678', '1234567!', 'Password!', 'Password1 '] as $case => $password) {
            yield 'policy '.$case => ['policy', ApplicationFailureCode::PasswordPolicyViolation, $password];
        }
        yield 'missing' => ['missing', ApplicationFailureCode::AuthenticationRequired, 'New password1!'];
        yield 'inactive' => ['inactive', ApplicationFailureCode::AuthenticationRequired, 'New password1!'];
        yield 'wrong' => ['wrong', ApplicationFailureCode::InvalidCredentials, 'New password1!'];
    }

    public function testCurrentPasswordIsLiteralAndNewUnicodePasswordIsPassedUnchanged(): void
    {
        $repository = $this->createMock(PasswordChangeRepositoryPort::class);
        $repository->expects(self::once())->method('accountForUpdate')->with('user')->willReturn(new StoredLoginIdentity('user', 'Name', 'user@example.test', 'private-hash', true));
        $repository->expects(self::once())->method('replacePasswordHash')->with('user', 'new-private-hash');
        $hasher = $this->createMock(PasswordHashingPort::class);
        $hasher->expects(self::exactly(2))->method('verify')->willReturnCallback(static fn (string $password): bool => ' old ' === $password);
        $hasher->expects(self::once())->method('hash')->with('  New password1 😀  ')->willReturn('new-private-hash');
        $sessions = $this->createMock(UserSessionRepositoryPort::class);
        $sessions->expects(self::once())->method('revokeAllUnfinishedForUser')->with('user', new DateTimeImmutable('2026-10-10T12:00:00Z'));
        $this->handler($repository, $sessions, $hasher)->handle(new ChangePasswordCommand('user', ' old ', '  New password1 😀  '));
    }

    public function testSamePasswordDoesNotChangeCredentialsOrSessions(): void
    {
        $repository = $this->createMock(PasswordChangeRepositoryPort::class);
        $repository->method('accountForUpdate')->willReturn(new StoredLoginIdentity('user', 'Name', 'user@example.test', 'private-hash', true));
        $repository->expects(self::never())->method('replacePasswordHash');
        $hasher = $this->createMock(PasswordHashingPort::class);
        $hasher->expects(self::exactly(2))->method('verify')->willReturn(true);
        $hasher->expects(self::never())->method('hash');
        $sessions = $this->createMock(UserSessionRepositoryPort::class);
        $sessions->expects(self::never())->method('revokeAllUnfinishedForUser');
        try {
            $this->handler($repository, $sessions, $hasher)->handle(new ChangePasswordCommand('user', 'Same password1!', 'Same password1!'));
            self::fail('The command must be denied.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::PasswordUnchanged, $failure->failureCode);
        }
    }

    private function handler(PasswordChangeRepositoryPort $repository, UserSessionRepositoryPort $sessions, PasswordHashingPort $hasher): ChangePassword
    {
        $transaction = $this->createStub(TransactionPort::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $clock = $this->createStub(ClockPort::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-10-10T12:00:00Z'));

        return new ChangePassword($repository, $sessions, new PasswordPolicy(), $hasher, $transaction, $clock);
    }
}
