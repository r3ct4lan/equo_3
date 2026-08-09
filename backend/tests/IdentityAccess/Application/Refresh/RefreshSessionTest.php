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
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Application\Refresh\RefreshCommand;
use App\IdentityAccess\Application\Refresh\RefreshSession;
use App\IdentityAccess\Domain\Access\UserSession;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RefreshSessionTest extends TestCase
{
    public function testSuccessfulRefreshRotatesExistingSessionAndIssuesNewTokens(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $session = UserSession::create(
            '00000000-0000-4000-8000-000000000101',
            '00000000-0000-4000-8000-000000000001',
            $this->hash('old'),
            $now->modify('-1 hour'),
        );
        $sessionRepository = $this->createMock(UserSessionRepositoryPort::class);
        $sessionRepository->expects(self::once())->method('findByRefreshTokenHashForUpdate')->with($this->hash('old'))->willReturn($session);
        $sessionRepository->expects(self::once())->method('save')->willReturnCallback(
            function (UserSession $saved) use ($session, $now): void {
                self::assertSame($session->id, $saved->id);
                self::assertSame($session->userId, $saved->userId);
                self::assertEquals($now->modify('-1 hour'), $saved->createdAt);
                self::assertEquals($now->modify('-1 hour')->modify('+30 days'), $saved->expiresAt);
                self::assertSame($this->hash('new'), $saved->refreshTokenHash());
            },
        );
        $identityRepository = $this->createMock(IdentityRepositoryPort::class);
        $identityRepository->expects(self::once())->method('currentUserState')->with($session->userId)->willReturn(new CurrentUserState($session->userId, true));
        $csrf = $this->createMock(CsrfTokenCodecPort::class);
        $csrf->expects(self::once())->method('verify')->with($session->id, 'csrf-old')->willReturn(true);
        $csrf->expects(self::once())->method('issue')->with($session->id)->willReturn('csrf-new');
        $access = $this->createMock(AccessTokenIssuerPort::class);
        $access->expects(self::once())->method('issue')->with($session->userId)->willReturn(new IssuedAccessToken('access-new', 900));

        $result = $this->handler(
            sessionRepository: $sessionRepository,
            identityRepository: $identityRepository,
            csrf: $csrf,
            access: $access,
            now: $now,
        )->handle(new RefreshCommand('rt.old', 'csrf-old'));

        self::assertSame('access-new', $result->accessToken);
        self::assertSame(900, $result->expiresIn);
        $result->withCookieSecrets(function (string $refreshToken, string $csrfToken, DateTimeImmutable $expiresAt) use ($session): void {
            self::assertSame('rt.new', $refreshToken);
            self::assertSame('csrf-new', $csrfToken);
            self::assertEquals($session->expiresAt, $expiresAt);
        });
    }

    public function testMalformedUnknownExpiredRevokedAndBadCsrfBranchesDoNotSave(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');

        $cases = [
            'malformed' => [null, null, true, ApplicationFailureCode::InvalidRefreshToken],
            'unknown' => [$this->hash('old'), null, true, ApplicationFailureCode::InvalidRefreshToken],
            'expired' => [$this->hash('old'), UserSession::create('00000000-0000-4000-8000-000000000102', '00000000-0000-4000-8000-000000000001', $this->hash('old'), $now->modify('-31 days')), true, ApplicationFailureCode::InvalidRefreshToken],
            'revoked' => [$this->hash('old'), $this->revokedSession($now), true, ApplicationFailureCode::InvalidRefreshToken],
            'bad-csrf' => [$this->hash('old'), UserSession::create('00000000-0000-4000-8000-000000000103', '00000000-0000-4000-8000-000000000001', $this->hash('old'), $now), false, ApplicationFailureCode::Forbidden],
        ];

        foreach ($cases as [$digest, $session, $csrfValid, $expected]) {
            $sessionRepository = $this->createMock(UserSessionRepositoryPort::class);
            $sessionRepository->expects(self::never())->method('save');
            if (null !== $digest) {
                $sessionRepository->expects(self::once())->method('findByRefreshTokenHashForUpdate')->willReturn($session);
            }

            try {
                $this->handler(
                    sessionRepository: $sessionRepository,
                    csrf: $this->csrf(valid: $csrfValid),
                    now: $now,
                    digest: $digest,
                )->handle(new RefreshCommand('rt.old', 'csrf-old'));
                self::fail('Refresh failure branch must throw.');
            } catch (ApplicationFailure $failure) {
                self::assertSame($expected, $failure->failureCode);
            }
        }
    }

    public function testInactiveOrMissingOwnerDoesNotRotate(): void
    {
        foreach ([null, new CurrentUserState('00000000-0000-4000-8000-000000000001', false)] as $owner) {
            $session = UserSession::create(
                '00000000-0000-4000-8000-000000000111',
                '00000000-0000-4000-8000-000000000001',
                $this->hash('old'),
                new DateTimeImmutable('2026-08-08T12:00:00Z'),
            );
            $sessionRepository = $this->createMock(UserSessionRepositoryPort::class);
            $sessionRepository->method('findByRefreshTokenHashForUpdate')->willReturn($session);
            $sessionRepository->expects(self::never())->method('save');
            $identityRepository = $this->createStub(IdentityRepositoryPort::class);
            $identityRepository->method('currentUserState')->willReturn($owner);

            try {
                $this->handler(sessionRepository: $sessionRepository, identityRepository: $identityRepository)
                    ->handle(new RefreshCommand('rt.old', 'csrf-old'));
                self::fail('Owner failure branch must throw.');
            } catch (ApplicationFailure $failure) {
                self::assertSame(null === $owner ? ApplicationFailureCode::InvalidRefreshToken : ApplicationFailureCode::AccountInactive, $failure->failureCode);
            }
        }
    }

    public function testTokenIssuanceFailureEscapesForTransactionRollback(): void
    {
        $access = $this->createStub(AccessTokenIssuerPort::class);
        $access->method('issue')->willThrowException(new RuntimeException('Controlled access failure.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Controlled access failure.');

        $this->handler(access: $access)->handle(new RefreshCommand('rt.old', 'csrf-old'));
    }

    private function handler(
        ?UserSessionRepositoryPort $sessionRepository = null,
        ?IdentityRepositoryPort $identityRepository = null,
        ?CsrfTokenCodecPort $csrf = null,
        ?AccessTokenIssuerPort $access = null,
        ?DateTimeImmutable $now = null,
        ?string $digest = null,
    ): RefreshSession {
        $now ??= new DateTimeImmutable('2026-08-08T12:00:00Z');
        $sessionRepository ??= $this->sessionRepository($now);
        $identityRepository ??= $this->identityRepository();

        return new RefreshSession(
            $sessionRepository,
            $identityRepository,
            $this->refreshCodec($digest ?? $this->hash('old')),
            $csrf ?? $this->csrf(),
            $access ?? $this->access(),
            $this->transaction(),
            $this->clock($now),
        );
    }

    private function sessionRepository(DateTimeImmutable $now): UserSessionRepositoryPort
    {
        $repository = $this->createStub(UserSessionRepositoryPort::class);
        $repository->method('findByRefreshTokenHashForUpdate')->willReturn(UserSession::create(
            '00000000-0000-4000-8000-000000000101',
            '00000000-0000-4000-8000-000000000001',
            $this->hash('old'),
            $now,
        ));

        return $repository;
    }

    private function identityRepository(): IdentityRepositoryPort
    {
        $repository = $this->createStub(IdentityRepositoryPort::class);
        $repository->method('currentUserState')->willReturn(new CurrentUserState('00000000-0000-4000-8000-000000000001', true));

        return $repository;
    }

    private function refreshCodec(?string $digest): RefreshTokenCodecPort
    {
        $codec = $this->createStub(RefreshTokenCodecPort::class);
        $codec->method('digest')->willReturn($digest);
        $codec->method('issue')->willReturn(new IssuedRefreshToken('rt.new', $this->hash('new')));

        return $codec;
    }

    private function csrf(bool $valid = true): CsrfTokenCodecPort
    {
        $codec = $this->createStub(CsrfTokenCodecPort::class);
        $codec->method('verify')->willReturn($valid);
        $codec->method('issue')->willReturn('csrf-new');

        return $codec;
    }

    private function access(): AccessTokenIssuerPort
    {
        $issuer = $this->createStub(AccessTokenIssuerPort::class);
        $issuer->method('issue')->willReturn(new IssuedAccessToken('access-new', 900));

        return $issuer;
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

    private function revokedSession(DateTimeImmutable $now): UserSession
    {
        $session = UserSession::create('00000000-0000-4000-8000-000000000104', '00000000-0000-4000-8000-000000000001', $this->hash('old'), $now);
        $session->revoke($now->modify('+1 hour'));

        return $session;
    }

    private function hash(string $seed): string
    {
        return 'sha256:'.hash('sha256', $seed);
    }
}
