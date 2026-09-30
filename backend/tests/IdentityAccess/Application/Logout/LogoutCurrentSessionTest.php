<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\Logout;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Logout\LogoutCurrentSession;
use App\IdentityAccess\Application\Logout\LogoutCurrentSessionCommand;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\ParsedRefreshToken;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Domain\Access\UserSession;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LogoutCurrentSessionTest extends TestCase
{
    private const SESSION_ID = '00000000-0000-4000-8000-000000000101';
    private const USER_ID = '00000000-0000-4000-8000-000000000001';

    public function testActiveKnownSessionIsRevokedAndSavedInsideTransaction(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $session = $this->activeSession($now);
        $repository = $this->createMock(UserSessionRepositoryPort::class);
        $repository->expects(self::once())->method('findByIdForUpdate')->with(self::SESSION_ID)->willReturn($session);
        $repository->expects(self::once())->method('save')->with($session);
        $transaction = $this->createMock(TransactionPort::class);
        $transaction->expects(self::once())->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        $this->handler($repository, transaction: $transaction, now: $now)
            ->handle(new LogoutCurrentSessionCommand('current-token', 'csrf-ok'));

        self::assertEquals($now, $session->revokedAt());
    }

    public function testStaleRotatedCredentialStillRevokesLocatedSession(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $session = $this->activeSession($now, 'sha256:'.hash('sha256', 'new-current-token'));
        $repository = $this->createMock(UserSessionRepositoryPort::class);
        $repository->expects(self::once())->method('findByIdForUpdate')->with(self::SESSION_ID)->willReturn($session);
        $repository->expects(self::once())->method('save')->with($session);

        $this->handler(
            $repository,
            refresh: $this->parsedRefresh('sha256:'.hash('sha256', 'stale-token')),
            now: $now,
        )->handle(new LogoutCurrentSessionCommand('stale-token', 'csrf-ok'));

        self::assertEquals($now, $session->revokedAt());
        self::assertSame('sha256:'.hash('sha256', 'new-current-token'), $session->refreshTokenHash());
    }

    public function testMalformedCredentialIsNoOpBeforeTransactionAndDatabase(): void
    {
        $repository = $this->createMock(UserSessionRepositoryPort::class);
        $repository->expects(self::never())->method('findByIdForUpdate');
        $repository->expects(self::never())->method('save');
        $transaction = $this->createMock(TransactionPort::class);
        $transaction->expects(self::never())->method('run');
        $refresh = $this->createStub(RefreshTokenCodecPort::class);
        $refresh->method('parse')->willReturn(null);

        $this->handler($repository, $refresh, transaction: $transaction)
            ->handle(new LogoutCurrentSessionCommand('malformed', 'csrf-any'));
    }

    public function testUnknownLocatorIsNoOpWithoutCsrfOrWrite(): void
    {
        $repository = $this->createMock(UserSessionRepositoryPort::class);
        $repository->expects(self::once())->method('findByIdForUpdate')->with(self::SESSION_ID)->willReturn(null);
        $repository->expects(self::never())->method('save');
        $csrf = $this->createMock(CsrfTokenCodecPort::class);
        $csrf->expects(self::never())->method('verify');

        $this->handler($repository, csrf: $csrf)->handle(new LogoutCurrentSessionCommand('unknown-token', 'csrf-any'));
    }

    public function testInvalidCsrfForKnownSessionIsForbiddenWithoutWrite(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $session = $this->activeSession($now);
        $repository = $this->createMock(UserSessionRepositoryPort::class);
        $repository->method('findByIdForUpdate')->willReturn($session);
        $repository->expects(self::never())->method('save');
        $csrf = $this->createMock(CsrfTokenCodecPort::class);
        $csrf->expects(self::once())->method('verify')->with(self::SESSION_ID, 'csrf-bad')->willReturn(false);

        try {
            $this->handler($repository, csrf: $csrf, now: $now)
                ->handle(new LogoutCurrentSessionCommand('current-token', 'csrf-bad'));
            self::fail('Invalid CSRF must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::Forbidden, $failure->failureCode);
        }

        self::assertNull($session->revokedAt());
    }

    public function testAlreadyRevokedSessionKeepsFirstTimestampWithoutWrite(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $firstRevocation = $now->modify('-1 hour');
        $session = UserSession::rehydrate(
            self::SESSION_ID,
            self::USER_ID,
            'sha256:'.hash('sha256', 'current-token'),
            $now->modify('-1 day'),
            $now->modify('+29 days'),
            $firstRevocation,
        );
        $repository = $this->repositoryWithoutWrite($session);

        $this->handler($repository, now: $now)->handle(new LogoutCurrentSessionCommand('current-token', 'csrf-ok'));

        self::assertEquals($firstRevocation, $session->revokedAt());
    }

    public function testExpiredSessionRemainsUnchangedWithoutWrite(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $session = UserSession::rehydrate(
            self::SESSION_ID,
            self::USER_ID,
            'sha256:'.hash('sha256', 'current-token'),
            $now->modify('-31 days'),
            $now,
            null,
        );

        $this->handler($this->repositoryWithoutWrite($session), now: $now)
            ->handle(new LogoutCurrentSessionCommand('current-token', 'csrf-ok'));

        self::assertNull($session->revokedAt());
    }

    private function handler(
        UserSessionRepositoryPort $repository,
        ?RefreshTokenCodecPort $refresh = null,
        ?CsrfTokenCodecPort $csrf = null,
        ?TransactionPort $transaction = null,
        ?DateTimeImmutable $now = null,
    ): LogoutCurrentSession {
        $refresh ??= $this->parsedRefresh('sha256:'.hash('sha256', 'current-token'));
        if (null === $csrf) {
            $csrf = $this->createStub(CsrfTokenCodecPort::class);
            $csrf->method('verify')->willReturn(true);
        }
        if (null === $transaction) {
            $transaction = $this->createStub(TransactionPort::class);
            $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        }
        $clock = $this->createStub(ClockPort::class);
        $clock->method('now')->willReturn($now ?? new DateTimeImmutable('2026-08-08T12:00:00Z'));

        return new LogoutCurrentSession($repository, $refresh, $csrf, $transaction, $clock);
    }

    private function activeSession(DateTimeImmutable $now, ?string $hash = null): UserSession
    {
        return UserSession::create(
            self::SESSION_ID,
            self::USER_ID,
            $hash ?? 'sha256:'.hash('sha256', 'current-token'),
            $now->modify('-1 day'),
        );
    }

    private function parsedRefresh(string $hash): RefreshTokenCodecPort
    {
        $refresh = $this->createStub(RefreshTokenCodecPort::class);
        $refresh->method('parse')->willReturn(new ParsedRefreshToken(self::SESSION_ID, $hash));

        return $refresh;
    }

    private function repositoryWithoutWrite(UserSession $session): UserSessionRepositoryPort
    {
        $repository = $this->createMock(UserSessionRepositoryPort::class);
        $repository->method('findByIdForUpdate')->willReturn($session);
        $repository->expects(self::never())->method('save');

        return $repository;
    }
}
