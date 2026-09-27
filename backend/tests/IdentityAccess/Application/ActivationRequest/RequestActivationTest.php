<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\ActivationRequest;

use App\IdentityAccess\Application\ActivationRequest\ActivationRequestCommand;
use App\IdentityAccess\Application\ActivationRequest\RequestActivation;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ActivationRequestAccount;
use App\IdentityAccess\Application\Port\ActivationRequestRepositoryPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\EmailOutboxPort;
use App\IdentityAccess\Application\Port\IssuedActionToken;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\PayloadCipherPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Domain\Access\UserActionToken;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestActivationTest extends TestCase
{
    public function testEligibleAccountGetsOneReplacementTokenAndOutboxIntent(): void
    {
        $now = new DateTimeImmutable('2026-09-27T10:00:00Z');
        $repository = $this->createMock(ActivationRequestRepositoryPort::class);
        $repository->expects(self::once())
            ->method('accountForUpdate')
            ->with('user@example.test')
            ->willReturn(new ActivationRequestAccount(
                '00000000-0000-4000-8000-000000000001',
                'user@example.test',
                'stored-password-hash',
                false,
            ));
        $repository->expects(self::once())
            ->method('replaceActivationToken')
            ->willReturnCallback(static function (UserActionToken $token, DateTimeImmutable $invalidatedAt) use ($now): void {
                self::assertSame('00000000-0000-4000-8000-000000000002', $token->id);
                self::assertSame('00000000-0000-4000-8000-000000000001', $token->userId);
                self::assertSame('v1:token-hash', $token->tokenHash);
                self::assertEquals($now, $token->createdAt);
                self::assertEquals($now->modify('+24 hours'), $token->expiresAt);
                self::assertEquals($now, $invalidatedAt);
            });
        $passwordHasher = $this->createMock(PasswordHashingPort::class);
        $passwordHasher->expects(self::once())
            ->method('verify')
            ->with('Correct password!', 'stored-password-hash')
            ->willReturn(true);
        $outbox = $this->createMock(EmailOutboxPort::class);
        $outbox->expects(self::once())->method('enqueueActivation')->with(
            '00000000-0000-4000-8000-000000000003',
            '00000000-0000-4000-8000-000000000002',
            'user@example.test',
            'encrypted-payload',
            $now,
        );

        $result = $this->handler($repository, $passwordHasher, $outbox, $now)->handle(
            new ActivationRequestCommand('  User@Example.Test  ', 'Correct password!', '192.0.2.1'),
        );

        self::assertSame('activation_email_scheduled', $result->status);
    }

    public function testUnknownAccountDoesNotVerifyPasswordOrCreateAnything(): void
    {
        $repository = $this->createMock(ActivationRequestRepositoryPort::class);
        $repository->expects(self::once())->method('accountForUpdate')->willReturn(null);
        $repository->expects(self::never())->method('replaceActivationToken');
        $passwordHasher = $this->createMock(PasswordHashingPort::class);
        $passwordHasher->expects(self::never())->method('verify');
        $outbox = $this->createMock(EmailOutboxPort::class);
        $outbox->expects(self::never())->method('enqueueActivation');

        $result = $this->handler(
            $repository,
            $passwordHasher,
            $outbox,
            new DateTimeImmutable('2026-09-27T10:00:00Z'),
            issueToken: false,
            normalizedEmail: 'unknown@example.test',
        )->handle(new ActivationRequestCommand('unknown@example.test', 'Any password!', '192.0.2.1'));

        self::assertSame('activation_email_scheduled', $result->status);
    }

    #[DataProvider('ineligibleAccountProvider')]
    public function testIneligibleAccountGetsSameResultWithoutWrites(bool $active, bool $passwordValid): void
    {
        $repository = $this->createMock(ActivationRequestRepositoryPort::class);
        $repository->method('accountForUpdate')->willReturn(new ActivationRequestAccount(
            '00000000-0000-4000-8000-000000000001',
            'user@example.test',
            'stored-password-hash',
            $active,
        ));
        $repository->expects(self::never())->method('replaceActivationToken');
        $passwordHasher = $this->createMock(PasswordHashingPort::class);
        $passwordHasher->expects(self::once())->method('verify')->willReturn($passwordValid);
        $outbox = $this->createMock(EmailOutboxPort::class);
        $outbox->expects(self::never())->method('enqueueActivation');

        $result = $this->handler(
            $repository,
            $passwordHasher,
            $outbox,
            new DateTimeImmutable('2026-09-27T10:00:00Z'),
            issueToken: false,
        )->handle(new ActivationRequestCommand('user@example.test', 'Submitted password!', '192.0.2.1'));

        self::assertSame('activation_email_scheduled', $result->status);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function ineligibleAccountProvider(): iterable
    {
        yield 'wrong password' => [false, false];
        yield 'active account' => [true, true];
    }

    public function testRateLimitIsCheckedWithNormalizedEmailBeforeLookupOrWrites(): void
    {
        $repository = $this->createMock(ActivationRequestRepositoryPort::class);
        $repository->expects(self::never())->method('accountForUpdate');
        $repository->expects(self::never())->method('replaceActivationToken');
        $passwordHasher = $this->createMock(PasswordHashingPort::class);
        $passwordHasher->expects(self::never())->method('verify');
        $outbox = $this->createMock(EmailOutboxPort::class);
        $outbox->expects(self::never())->method('enqueueActivation');

        try {
            $this->handler(
                $repository,
                $passwordHasher,
                $outbox,
                new DateTimeImmutable('2026-09-27T10:00:00Z'),
                issueToken: false,
                retryAfter: 123,
            )->handle(new ActivationRequestCommand('  User@Example.Test  ', 'Any password!', '192.0.2.9'));
            self::fail('A rate-limited activation request must fail before account lookup.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::RateLimitExceeded, $failure->failureCode);
            self::assertSame(123, $failure->retryAfter);
        }
    }

    private function handler(
        ActivationRequestRepositoryPort $repository,
        PasswordHashingPort $passwordHasher,
        EmailOutboxPort $outbox,
        DateTimeImmutable $now,
        bool $issueToken = true,
        ?int $retryAfter = null,
        string $normalizedEmail = 'user@example.test',
    ): RequestActivation {
        $tokenCodec = $this->createMock(ActionTokenCodecPort::class);
        $expectation = $issueToken ? $tokenCodec->expects(self::once()) : $tokenCodec->expects(self::never());
        $expectation->method('issue')->willReturn(new IssuedActionToken('v1.public-token', 'v1:token-hash'));

        $payloadCipher = $this->createStub(PayloadCipherPort::class);
        $payloadCipher->method('encrypt')->willReturn('encrypted-payload');
        $rateLimit = $this->createMock(RateLimitPort::class);
        $rateLimit->expects(self::once())
            ->method('activationRequestRetryAfter')
            ->with(self::anything(), $normalizedEmail)
            ->willReturn($retryAfter);
        $transaction = $this->createStub(TransactionPort::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $clock = $this->createStub(ClockPort::class);
        $clock->method('now')->willReturn($now);
        $uuid = $this->createStub(UuidPort::class);
        $uuid->method('generate')->willReturnOnConsecutiveCalls(
            '00000000-0000-4000-8000-000000000002',
            '00000000-0000-4000-8000-000000000003',
        );

        return new RequestActivation(
            $repository,
            $passwordHasher,
            $tokenCodec,
            $payloadCipher,
            $outbox,
            $rateLimit,
            $transaction,
            $clock,
            $uuid,
        );
    }
}
