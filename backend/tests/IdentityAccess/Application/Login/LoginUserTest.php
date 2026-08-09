<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\Login;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Login\LoginCommand;
use App\IdentityAccess\Application\Login\LoginUser;
use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\IssuedAccessToken;
use App\IdentityAccess\Application\Port\IssuedRefreshToken;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Port\StoredLoginIdentity;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Domain\Access\UserSession;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LoginUserTest extends TestCase
{
    public function testSuccessfulLoginCreatesSessionAndIssuesTokens(): void
    {
        $now = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $identity = new StoredLoginIdentity(
            '00000000-0000-4000-8000-000000000001',
            'Alex Doe',
            'user@example.test',
            'password-hash',
            true,
        );
        $repository = $this->createMock(IdentityRepositoryPort::class);
        $repository->expects(self::once())->method('loginIdentityByEmail')->with('user@example.test')->willReturn($identity);
        $passwordHasher = $this->createMock(PasswordHashingPort::class);
        $passwordHasher->expects(self::once())->method('verify')->with('raw password', 'password-hash')->willReturn(true);
        $sessionRepository = $this->createMock(UserSessionRepositoryPort::class);
        $sessionRepository->expects(self::once())->method('add')->willReturnCallback(
            static function (UserSession $session) use ($now): void {
                self::assertSame('00000000-0000-4000-8000-000000000101', $session->id);
                self::assertSame('00000000-0000-4000-8000-000000000001', $session->userId);
                self::assertSame('sha256:'.hash('sha256', 'refresh'), $session->refreshTokenHash());
                self::assertEquals($now->modify('+30 days'), $session->expiresAt);
            },
        );
        $accessTokenIssuer = $this->createMock(AccessTokenIssuerPort::class);
        $accessTokenIssuer->expects(self::once())->method('issue')->with($identity->id)->willReturn(new IssuedAccessToken('access.jwt', 900));
        $csrf = $this->createMock(CsrfTokenCodecPort::class);
        $csrf->expects(self::once())->method('issue')->with('00000000-0000-4000-8000-000000000101')->willReturn('csrf-token');

        $result = $this->handler(
            repository: $repository,
            sessionRepository: $sessionRepository,
            passwordHasher: $passwordHasher,
            accessTokenIssuer: $accessTokenIssuer,
            csrfTokenCodec: $csrf,
            now: $now,
        )->handle(new LoginCommand('  User@Example.Test  ', 'raw password', '192.0.2.1'));

        self::assertSame('access.jwt', $result->accessToken);
        self::assertSame(900, $result->expiresIn);
        self::assertSame('user@example.test', $result->user->email);
        $result->withCookieSecrets(static function (string $refreshToken, string $csrfToken, DateTimeImmutable $expiresAt) use ($now): void {
            self::assertSame('rt.public', $refreshToken);
            self::assertSame('csrf-token', $csrfToken);
            self::assertEquals($now->modify('+30 days'), $expiresAt);
        });
    }

    public function testUnknownEmailAndWrongPasswordUseSameFailureAndVerificationBoundary(): void
    {
        foreach ([null, new StoredLoginIdentity('id', 'Name', 'user@example.test', 'password-hash', true)] as $identity) {
            $repository = $this->createMock(IdentityRepositoryPort::class);
            $repository->expects(self::once())->method('loginIdentityByEmail')->willReturn($identity);
            $passwordHasher = $this->createMock(PasswordHashingPort::class);
            $passwordHasher->expects(self::once())
                ->method('verify')
                ->with('wrong password', null === $identity ? null : 'password-hash')
                ->willReturn(false);
            $sessionRepository = $this->createMock(UserSessionRepositoryPort::class);
            $sessionRepository->expects(self::never())->method('add');

            try {
                $this->handler(
                    repository: $repository,
                    sessionRepository: $sessionRepository,
                    passwordHasher: $passwordHasher,
                )->handle(new LoginCommand('user@example.test', 'wrong password', '192.0.2.1'));
                self::fail('Invalid credentials must fail.');
            } catch (ApplicationFailure $failure) {
                self::assertSame(ApplicationFailureCode::InvalidCredentials, $failure->failureCode);
            }
        }
    }

    public function testInactiveAccountOnlyLeaksAfterCorrectPassword(): void
    {
        $identity = new StoredLoginIdentity('id', 'Name', 'user@example.test', 'password-hash', false);
        $repository = $this->createStub(IdentityRepositoryPort::class);
        $repository->method('loginIdentityByEmail')->willReturn($identity);
        $sessionRepository = $this->createMock(UserSessionRepositoryPort::class);
        $sessionRepository->expects(self::never())->method('add');

        try {
            $this->handler(
                repository: $repository,
                sessionRepository: $sessionRepository,
                passwordHasher: $this->passwordHasher(false),
            )->handle(new LoginCommand('user@example.test', 'wrong password', '192.0.2.1'));
            self::fail('Wrong password for inactive user must remain invalid credentials.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::InvalidCredentials, $failure->failureCode);
        }

        try {
            $this->handler(
                repository: $repository,
                sessionRepository: $sessionRepository,
                passwordHasher: $this->passwordHasher(true),
            )->handle(new LoginCommand('user@example.test', 'right password', '192.0.2.1'));
            self::fail('Inactive account must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::AccountInactive, $failure->failureCode);
        }
    }

    public function testRateLimitStopsBeforeLookupSessionOrTokenIssuance(): void
    {
        $repository = $this->createMock(IdentityRepositoryPort::class);
        $repository->expects(self::never())->method('loginIdentityByEmail');
        $sessionRepository = $this->createMock(UserSessionRepositoryPort::class);
        $sessionRepository->expects(self::never())->method('add');
        $accessTokenIssuer = $this->createMock(AccessTokenIssuerPort::class);
        $accessTokenIssuer->expects(self::never())->method('issue');

        $rateLimit = $this->createStub(RateLimitPort::class);
        $rateLimit->method('loginRetryAfter')->willReturn(123);

        try {
            $this->handler(
                repository: $repository,
                sessionRepository: $sessionRepository,
                accessTokenIssuer: $accessTokenIssuer,
                rateLimit: $rateLimit,
            )->handle(new LoginCommand('user@example.test', 'password', '192.0.2.1'));
            self::fail('Rate limited login must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::RateLimitExceeded, $failure->failureCode);
            self::assertSame(123, $failure->retryAfter);
        }
    }

    public function testTechnicalFailureEscapesWithoutPartialResult(): void
    {
        $identity = new StoredLoginIdentity('00000000-0000-4000-8000-000000000001', 'Name', 'user@example.test', 'password-hash', true);
        $repository = $this->createStub(IdentityRepositoryPort::class);
        $repository->method('loginIdentityByEmail')->willReturn($identity);
        $accessTokenIssuer = $this->createStub(AccessTokenIssuerPort::class);
        $accessTokenIssuer->method('issue')->willThrowException(new RuntimeException('Controlled token failure.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Controlled token failure.');

        $this->handler(repository: $repository, accessTokenIssuer: $accessTokenIssuer)
            ->handle(new LoginCommand('user@example.test', 'password', '192.0.2.1'));
    }

    private function handler(
        ?IdentityRepositoryPort $repository = null,
        ?UserSessionRepositoryPort $sessionRepository = null,
        ?PasswordHashingPort $passwordHasher = null,
        ?AccessTokenIssuerPort $accessTokenIssuer = null,
        ?CsrfTokenCodecPort $csrfTokenCodec = null,
        ?RateLimitPort $rateLimit = null,
        ?DateTimeImmutable $now = null,
    ): LoginUser {
        $now ??= new DateTimeImmutable('2026-08-08T12:00:00Z');
        if (null === $rateLimit) {
            $rateLimit = $this->createStub(RateLimitPort::class);
            $rateLimit->method('loginRetryAfter')->willReturn(null);
        }

        return new LoginUser(
            $repository ?? $this->identityRepository(),
            $sessionRepository ?? $this->createStub(UserSessionRepositoryPort::class),
            $passwordHasher ?? $this->passwordHasher(true),
            $accessTokenIssuer ?? $this->accessTokenIssuer(),
            $this->refreshTokenCodec(),
            $csrfTokenCodec ?? $this->csrfTokenCodec(),
            $rateLimit,
            $this->transaction(),
            $this->clock($now),
            $this->uuid(),
        );
    }

    private function identityRepository(): IdentityRepositoryPort
    {
        $repository = $this->createStub(IdentityRepositoryPort::class);
        $repository->method('loginIdentityByEmail')->willReturn(new StoredLoginIdentity(
            '00000000-0000-4000-8000-000000000001',
            'Alex Doe',
            'user@example.test',
            'password-hash',
            true,
        ));

        return $repository;
    }

    private function passwordHasher(bool $valid): PasswordHashingPort
    {
        $passwordHasher = $this->createStub(PasswordHashingPort::class);
        $passwordHasher->method('verify')->willReturn($valid);

        return $passwordHasher;
    }

    private function accessTokenIssuer(): AccessTokenIssuerPort
    {
        $issuer = $this->createStub(AccessTokenIssuerPort::class);
        $issuer->method('issue')->willReturn(new IssuedAccessToken('access.jwt', 900));

        return $issuer;
    }

    private function refreshTokenCodec(): RefreshTokenCodecPort
    {
        $codec = $this->createStub(RefreshTokenCodecPort::class);
        $codec->method('issue')->willReturn(new IssuedRefreshToken('rt.public', 'sha256:'.hash('sha256', 'refresh')));

        return $codec;
    }

    private function csrfTokenCodec(): CsrfTokenCodecPort
    {
        $codec = $this->createStub(CsrfTokenCodecPort::class);
        $codec->method('issue')->willReturn('csrf-token');

        return $codec;
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

    private function uuid(): UuidPort
    {
        $uuid = $this->createStub(UuidPort::class);
        $uuid->method('generate')->willReturn('00000000-0000-4000-8000-000000000101');

        return $uuid;
    }
}
