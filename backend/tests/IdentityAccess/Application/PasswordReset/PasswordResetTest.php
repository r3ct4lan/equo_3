<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\PasswordReset;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\PasswordReset\PasswordResetRequestCommand;
use App\IdentityAccess\Application\PasswordReset\RequestPasswordReset;
use App\IdentityAccess\Application\PasswordReset\ResetPassword;
use App\IdentityAccess\Application\PasswordReset\ResetPasswordCommand;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\PasswordChangeRepositoryPort;
use App\IdentityAccess\Application\Port\StoredLoginIdentity;
use App\IdentityAccess\Application\Port\IssuedActionToken;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\PasswordResetAccount;
use App\IdentityAccess\Application\Port\PasswordResetOutboxPort;
use App\IdentityAccess\Application\Port\PasswordResetRateLimitPort;
use App\IdentityAccess\Application\Port\PasswordResetRepositoryPort;
use App\IdentityAccess\Application\Port\PayloadCipherPort;
use App\IdentityAccess\Application\Port\StoredPasswordResetToken;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\IdentityAccess\Domain\User\PasswordPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordResetTest extends TestCase
{
    public function testRequestNormalizesEmailAndAtomicallySchedulesEncryptedThirtyMinuteCapability(): void
    {
        $now = new DateTimeImmutable('2026-10-09T12:00:00Z');
        $repository = $this->createMock(PasswordResetRepositoryPort::class);
        $repository->expects(self::once())->method('accountForUpdate')->with('user@example.test')->willReturn(new PasswordResetAccount('user-id', 'user@example.test'));
        $repository->expects(self::once())->method('replaceToken')->willReturnCallback(static function (UserActionToken $token, DateTimeImmutable $at) use ($now): void {
            self::assertSame('token-id', $token->id);
            self::assertSame('user-id', $token->userId);
            self::assertSame('v1:digest', $token->tokenHash);
            self::assertSame(UserActionTokenPurpose::ResetPassword, $token->purpose);
            self::assertEquals($now, $token->createdAt);
            self::assertEquals($now->modify('+30 minutes'), $token->expiresAt);
            self::assertSame($now, $at);
        });
        $codec = $this->createStub(ActionTokenCodecPort::class);
        $codec->method('issue')->willReturn(new IssuedActionToken('v1.capability', 'v1:digest'));
        $cipher = $this->createMock(PayloadCipherPort::class);
        $cipher->expects(self::once())->method('encrypt')->with(['token' => 'v1.capability'])->willReturn('encrypted');
        $outbox = $this->createMock(PasswordResetOutboxPort::class);
        $outbox->expects(self::once())->method('enqueuePasswordReset')->with('delivery-id', 'token-id', 'user@example.test', 'encrypted', $now);
        $limits = $this->createMock(PasswordResetRateLimitPort::class);
        $limits->expects(self::once())->method('passwordResetRequestRetryAfter')->with('192.0.2.1', 'user@example.test')->willReturn(null);
        $uuid = $this->createStub(UuidPort::class);
        $uuid->method('generate')->willReturnOnConsecutiveCalls('token-id', 'delivery-id');
        (new RequestPasswordReset($repository, $codec, $cipher, $outbox, $limits, $this->transaction(), $this->clock($now), $uuid))
            ->handle(new PasswordResetRequestCommand('  USER@Example.Test  ', '192.0.2.1'));
    }

    #[DataProvider('requestFailureProvider')]
    public function testUnknownOrLimitedRequestNeverIssuesOrSchedules(bool $limited): void
    {
        $repository = $this->createMock(PasswordResetRepositoryPort::class);
        $repository->expects($limited ? self::never() : self::once())->method('accountForUpdate')->willReturn(null);
        $repository->expects(self::never())->method('replaceToken');
        $codec = $this->createMock(ActionTokenCodecPort::class);
        $codec->expects(self::never())->method('issue');
        $outbox = $this->createMock(PasswordResetOutboxPort::class);
        $outbox->expects(self::never())->method('enqueuePasswordReset');
        $limits = $this->createStub(PasswordResetRateLimitPort::class);
        $limits->method('passwordResetRequestRetryAfter')->willReturn($limited ? 123 : null);
        if ($limited) {
            $this->expectException(ApplicationFailure::class);
        }
        (new RequestPasswordReset($repository, $codec, $this->createStub(PayloadCipherPort::class), $outbox, $limits, $this->transaction(), $this->clock(new DateTimeImmutable()), $this->createStub(UuidPort::class)))
            ->handle(new PasswordResetRequestCommand('unknown@example.test', '192.0.2.1'));
    }

    /** @return iterable<string, array{bool}> */
    public static function requestFailureProvider(): iterable
    {
        yield 'unknown' => [false];
        yield 'limited' => [true];
    }

    public function testApplyLocksOwnerBeforeTokenAllowsInactiveAndPreservesExactPassword(): void
    {
        $now = new DateTimeImmutable('2026-10-09T12:00:00Z');
        $events = [];
        $repository = $this->createMock(PasswordResetRepositoryPort::class);
        $repository->method('userIdForTokenHash')->willReturn('user-id');
        $identities = $this->createMock(PasswordChangeRepositoryPort::class);
        $identities->expects(self::once())->method('accountForUpdate')->willReturnCallback(static function () use (&$events): StoredLoginIdentity {
            $events[] = 'owner';

            return new StoredLoginIdentity('user-id', 'Name', 'user@example.test', 'old-hash', false);
        });
        $repository->expects(self::once())->method('tokenForUpdate')->willReturnCallback(static function () use (&$events, $now): StoredPasswordResetToken {
            $events[] = 'token';

            return new StoredPasswordResetToken('token-id', 'user-id', UserActionTokenPurpose::ResetPassword, $now->modify('+1 minute'), null, null);
        });
        $password = '  ёжик-1😀-пароль  ';
        $hasher = $this->createMock(PasswordHashingPort::class);
        $hasher->expects(self::once())->method('hash')->with($password)->willReturn('new-hash');
        $repository->expects(self::once())->method('completeReset')->with('token-id', 'user-id', 'new-hash', $now);
        $sessions = $this->createMock(UserSessionRepositoryPort::class);
        $sessions->expects(self::once())->method('revokeAllUnfinishedForUser')->with('user-id', $now)->willReturn(2);
        $this->reset($repository, $identities, $sessions, $hasher, $now)->handle(new ResetPasswordCommand('v1.capability', $password, '192.0.2.1'));
        self::assertSame(['owner', 'token'], $events);
    }

    #[DataProvider('terminalProvider')]
    public function testInvalidOrTerminalTokenCannotHashOrMutate(string $state, ApplicationFailureCode $expected): void
    {
        $now = new DateTimeImmutable('2026-10-09T12:00:00Z');
        $repository = $this->createMock(PasswordResetRepositoryPort::class);
        $repository->method('userIdForTokenHash')->willReturn('unknown' === $state ? null : 'user-id');
        $repository->method('tokenForUpdate')->willReturn('missing' === $state ? null : new StoredPasswordResetToken(
            'token-id', 'foreign' === $state ? 'other-id' : 'user-id',
            'purpose' === $state ? UserActionTokenPurpose::ActivateAccount : UserActionTokenPurpose::ResetPassword,
            'expired' === $state ? $now : $now->modify('+1 minute'),
            'used' === $state ? $now : null, 'invalidated' === $state ? $now : null,
        ));
        $repository->expects(self::never())->method('completeReset');
        $identities = $this->createStub(PasswordChangeRepositoryPort::class);
        $identities->method('accountForUpdate')->willReturn(new StoredLoginIdentity('user-id', 'Name', 'user@example.test', 'old-hash', true));
        $hasher = $this->createMock(PasswordHashingPort::class);
        $hasher->expects(self::never())->method('hash');
        $sessions = $this->createMock(UserSessionRepositoryPort::class);
        $sessions->expects(self::never())->method('revokeAllUnfinishedForUser');
        try {
            $this->reset($repository, $identities, $sessions, $hasher, $now)->handle(new ResetPasswordCommand('v1.capability', 'New password1!', '192.0.2.1'));
            self::fail('A rejected token must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame($expected, $failure->failureCode);
        }
    }

    /** @return iterable<string, array{string, ApplicationFailureCode}> */
    public static function terminalProvider(): iterable
    {
        foreach (['unknown', 'missing', 'foreign', 'purpose'] as $state) {
            yield $state => [$state, ApplicationFailureCode::InvalidToken];
        }
        yield 'used' => ['used', ApplicationFailureCode::TokenUsed];
        yield 'invalidated' => ['invalidated', ApplicationFailureCode::TokenInvalidated];
        yield 'expired boundary' => ['expired', ApplicationFailureCode::TokenExpired];
    }

    #[DataProvider('earlyFailureProvider')]
    public function testPolicyMalformedAndQuotaFailuresNeverEnterPersistence(string $password, ?string $digest, ?int $retry, ApplicationFailureCode $expected): void
    {
        $repository = $this->createMock(PasswordResetRepositoryPort::class);
        $repository->expects(self::never())->method('userIdForTokenHash');
        $codec = $this->createStub(ActionTokenCodecPort::class);
        $codec->method('digest')->willReturn($digest);
        $limits = $this->createStub(PasswordResetRateLimitPort::class);
        $limits->method('passwordResetRetryAfter')->willReturn($retry);
        $hasher = $this->createMock(PasswordHashingPort::class);
        $hasher->expects(self::never())->method('hash');
        try {
            (new ResetPassword($repository, $this->createStub(PasswordChangeRepositoryPort::class), $this->createStub(UserSessionRepositoryPort::class), $codec, new PasswordPolicy(), $hasher, $limits, $this->transaction(), $this->clock(new DateTimeImmutable())))
                ->handle(new ResetPasswordCommand('bad', $password, '192.0.2.1'));
            self::fail('Precondition must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame($expected, $failure->failureCode);
            self::assertSame($retry, $failure->retryAfter);
        }
    }

    /** @return iterable<string, array{string, ?string, ?int, ApplicationFailureCode}> */
    public static function earlyFailureProvider(): iterable
    {
        yield 'short' => ['short', 'digest', null, ApplicationFailureCode::PasswordPolicyViolation];
        yield 'spaces' => [str_repeat(' ', 12), 'digest', null, ApplicationFailureCode::PasswordPolicyViolation];
        yield 'long unicode' => [str_repeat('😀', 129), 'digest', null, ApplicationFailureCode::PasswordPolicyViolation];
        yield 'digits only' => ['12345678', 'digest', null, ApplicationFailureCode::PasswordPolicyViolation];
        yield 'no letter' => ['1234567!', 'digest', null, ApplicationFailureCode::PasswordPolicyViolation];
        yield 'no digit' => ['Password!', 'digest', null, ApplicationFailureCode::PasswordPolicyViolation];
        yield 'space is not special' => ['Password1 ', 'digest', null, ApplicationFailureCode::PasswordPolicyViolation];
        yield 'malformed' => ['New password1!', null, null, ApplicationFailureCode::InvalidToken];
        yield 'limited' => ['New password1!', 'digest', 123, ApplicationFailureCode::RateLimitExceeded];
    }

    private function reset(PasswordResetRepositoryPort $repository, PasswordChangeRepositoryPort $identities, UserSessionRepositoryPort $sessions, PasswordHashingPort $hasher, DateTimeImmutable $now): ResetPassword
    {
        $codec = $this->createStub(ActionTokenCodecPort::class);
        $codec->method('digest')->willReturn('v1:digest');
        $limits = $this->createStub(PasswordResetRateLimitPort::class);
        $limits->method('passwordResetRetryAfter')->willReturn(null);

        return new ResetPassword($repository, $identities, $sessions, $codec, new PasswordPolicy(), $hasher, $limits, $this->transaction(), $this->clock($now));
    }

    private function transaction(): TransactionPort
    {
        $transaction = $this->createStub(TransactionPort::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return $transaction;
    }

    private function clock(DateTimeImmutable $now): ClockPort
    {
        $clock = $this->createStub(ClockPort::class);
        $clock->method('now')->willReturn($now);

        return $clock;
    }
}
