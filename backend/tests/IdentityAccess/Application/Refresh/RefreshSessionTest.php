<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\Refresh;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\CurrentUserState;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\IssuedAccessToken;
use App\IdentityAccess\Application\Port\IssuedRefreshToken;
use App\IdentityAccess\Application\Port\ParsedRefreshToken;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Application\Refresh\RefreshCommand;
use App\IdentityAccess\Application\Refresh\RefreshSession;
use App\IdentityAccess\Domain\Access\UserSession;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RefreshSessionTest extends TestCase
{
    private const SESSION_ID = '00000000-0000-4000-8000-000000000101';
    private const USER_ID = '00000000-0000-4000-8000-000000000001';

    public function testActiveSessionRotatesCredentialWithoutExtendingSession(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $session = $this->activeSession($now);
        $expiresAt = $session->expiresAt;
        $repository = $this->createMock(UserSessionRepositoryPort::class);
        $repository->expects(self::once())->method('findByIdForUpdate')->with(self::SESSION_ID)->willReturn($session);
        $repository->expects(self::once())->method('save')->with($session);
        $refresh = $this->createMock(RefreshTokenCodecPort::class);
        $refresh->method('parse')->willReturn(new ParsedRefreshToken(
            self::SESSION_ID,
            'sha256:'.hash('sha256', 'current'),
        ));
        $refresh->expects(self::once())->method('issueForSession')->with(self::SESSION_ID)->willReturn(
            new IssuedRefreshToken('rotated-token', 'sha256:'.hash('sha256', 'rotated'), self::SESSION_ID),
        );
        $csrf = $this->createMock(CsrfTokenCodecPort::class);
        $csrf->expects(self::once())->method('verify')->with(self::SESSION_ID, 'csrf-current')->willReturn(true);
        $csrf->expects(self::once())->method('issue')->with(self::SESSION_ID)->willReturn('csrf-rotated');

        $result = $this->handler($repository, $refresh, $csrf, now: $now)->handle(
            new RefreshCommand('current-token', 'csrf-current'),
        );

        self::assertSame('sha256:'.hash('sha256', 'rotated'), $session->refreshTokenHash());
        self::assertEquals($expiresAt, $session->expiresAt);
        self::assertSame('access.jwt', $result->accessToken);
        $result->withCookieSecrets(static function (string $refreshToken, string $csrfToken, DateTimeImmutable $resultExpiresAt) use ($expiresAt): void {
            self::assertSame('rotated-token', $refreshToken);
            self::assertSame('csrf-rotated', $csrfToken);
            self::assertEquals($expiresAt, $resultExpiresAt);
        });
    }

    public function testMalformedTokenFailsBeforeTransactionOrRepositoryLookup(): void
    {
        $repository = $this->createMock(UserSessionRepositoryPort::class);
        $repository->expects(self::never())->method('findByIdForUpdate');
        $transaction = $this->createMock(TransactionPort::class);
        $transaction->expects(self::never())->method('run');
        $refresh = $this->createStub(RefreshTokenCodecPort::class);
        $refresh->method('parse')->willReturn(null);

        $this->assertFailure(
            ApplicationFailureCode::InvalidRefreshToken,
            fn (): mixed => $this->handler($repository, $refresh, transaction: $transaction)->handle(new RefreshCommand('malformed', 'csrf')),
        );
    }

    public function testUnknownSessionLocatorIsRejected(): void
    {
        $repository = $this->createMock(UserSessionRepositoryPort::class);
        $repository->expects(self::once())->method('findByIdForUpdate')->with(self::SESSION_ID)->willReturn(null);
        $repository->expects(self::never())->method('save');

        $this->assertFailure(
            ApplicationFailureCode::InvalidRefreshToken,
            fn (): mixed => $this->handler($repository, $this->refreshCodec('sha256:'.hash('sha256', 'current')))
                ->handle(new RefreshCommand('current-token', 'csrf')),
        );
    }

    public function testStaleRotatedCredentialIsRejectedBeforeCsrfVerification(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $session = $this->activeSession($now, 'sha256:'.hash('sha256', 'new-current'));
        $repository = $this->repositoryReturning($session);
        $csrf = $this->createMock(CsrfTokenCodecPort::class);
        $csrf->expects(self::never())->method('verify');

        $this->assertFailure(
            ApplicationFailureCode::InvalidRefreshToken,
            fn (): mixed => $this->handler($repository, $this->refreshCodec('sha256:'.hash('sha256', 'stale')), $csrf, now: $now)
                ->handle(new RefreshCommand('stale-token', 'csrf')),
        );
    }

    public function testInvalidCsrfDoesNotRotateSession(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $session = $this->activeSession($now);
        $repository = $this->repositoryReturning($session);
        $csrf = $this->createStub(CsrfTokenCodecPort::class);
        $csrf->method('verify')->willReturn(false);

        $this->assertFailure(
            ApplicationFailureCode::Forbidden,
            fn (): mixed => $this->handler($repository, $this->refreshCodec('sha256:'.hash('sha256', 'current')), $csrf, now: $now)
                ->handle(new RefreshCommand('current-token', 'bad-csrf')),
        );
    }

    public function testRevokedOrExpiredSessionIsRejectedWithoutRotation(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $sessions = [
            UserSession::rehydrate(self::SESSION_ID, self::USER_ID, 'sha256:'.hash('sha256', 'current'), $now->modify('-1 day'), $now->modify('+1 day'), $now->modify('-1 hour')),
            UserSession::rehydrate(self::SESSION_ID, self::USER_ID, 'sha256:'.hash('sha256', 'current'), $now->modify('-2 days'), $now, null),
        ];

        foreach ($sessions as $session) {
            try {
                $this->handler(
                    $this->repositoryReturning($session),
                    $this->refreshCodec('sha256:'.hash('sha256', 'current')),
                    $this->validCsrf(),
                    now: $now,
                )->handle(new RefreshCommand('current-token', 'csrf'));
                self::fail('An inactive session must fail.');
            } catch (ApplicationFailure $failure) {
                self::assertSame(ApplicationFailureCode::InvalidRefreshToken, $failure->failureCode);
            }
        }
    }

    public function testMissingOrInactiveOwnerIsRejected(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        foreach ([null, new CurrentUserState(self::USER_ID, false)] as $owner) {
            $identities = $this->createStub(IdentityRepositoryPort::class);
            $identities->method('currentUserState')->willReturn($owner);

            try {
                $this->handler(
                    $this->repositoryReturning($this->activeSession($now)),
                    $this->refreshCodec('sha256:'.hash('sha256', 'current')),
                    $this->validCsrf(),
                    $identities,
                    now: $now,
                )->handle(new RefreshCommand('current-token', 'csrf'));
                self::fail('A missing or inactive owner must fail.');
            } catch (ApplicationFailure $failure) {
                self::assertSame(
                    null === $owner ? ApplicationFailureCode::InvalidRefreshToken : ApplicationFailureCode::AccountInactive,
                    $failure->failureCode,
                );
            }
        }
    }

    public function testTokenIssuanceFailureEscapesForTransactionRollback(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $refresh = $this->refreshCodec('sha256:'.hash('sha256', 'current'));
        $refresh->method('issueForSession')->willThrowException(new RuntimeException('Controlled issuance failure.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Controlled issuance failure.');
        $this->handler($this->repositoryReturning($this->activeSession($now)), $refresh, $this->validCsrf(), now: $now)
            ->handle(new RefreshCommand('current-token', 'csrf'));
    }

    private function handler(
        UserSessionRepositoryPort $repository,
        RefreshTokenCodecPort $refresh,
        ?CsrfTokenCodecPort $csrf = null,
        ?IdentityRepositoryPort $identities = null,
        ?TransactionPort $transaction = null,
        ?DateTimeImmutable $now = null,
    ): RefreshSession {
        $now ??= new DateTimeImmutable('2026-08-08T12:00:00Z');
        if (null === $identities) {
            $identities = $this->createStub(IdentityRepositoryPort::class);
            $identities->method('currentUserState')->willReturn(new CurrentUserState(self::USER_ID, true));
        }
        $access = $this->createStub(AccessTokenIssuerPort::class);
        $access->method('issue')->willReturn(new IssuedAccessToken('access.jwt', 900));
        $clock = $this->createStub(ClockPort::class);
        $clock->method('now')->willReturn($now);
        if (null === $transaction) {
            $defaultTransaction = $this->createStub(TransactionPort::class);
            $defaultTransaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
            $transaction = $defaultTransaction;
        }

        return new RefreshSession($repository, $identities, $refresh, $csrf ?? $this->validCsrf(), $access, $transaction, $clock);
    }

    private function activeSession(DateTimeImmutable $now, ?string $hash = null): UserSession
    {
        return UserSession::create(self::SESSION_ID, self::USER_ID, $hash ?? 'sha256:'.hash('sha256', 'current'), $now->modify('-1 day'));
    }

    private function repositoryReturning(UserSession $session): UserSessionRepositoryPort
    {
        $repository = $this->createMock(UserSessionRepositoryPort::class);
        $repository->method('findByIdForUpdate')->willReturn($session);
        $repository->expects(self::never())->method('save');

        return $repository;
    }

    /** @return RefreshTokenCodecPort&Stub */
    private function refreshCodec(string $parsedHash): RefreshTokenCodecPort
    {
        $refresh = $this->createStub(RefreshTokenCodecPort::class);
        $refresh->method('parse')->willReturn(new ParsedRefreshToken(self::SESSION_ID, $parsedHash));

        return $refresh;
    }

    private function validCsrf(): CsrfTokenCodecPort
    {
        $csrf = $this->createStub(CsrfTokenCodecPort::class);
        $csrf->method('verify')->willReturn(true);

        return $csrf;
    }

    /** @param callable(): mixed $operation */
    private function assertFailure(ApplicationFailureCode $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('The operation must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame($code, $failure->failureCode);
        }
    }
}
