<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\Register;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\EmailOutboxPort;
use App\IdentityAccess\Application\Port\IdempotencyPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\IssuedActionToken;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\PayloadCipherPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Application\Register\RegisterCommand;
use App\IdentityAccess\Application\Register\RegisterUser;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\User\PasswordPolicy;
use App\IdentityAccess\Domain\User\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RegisterUserTest extends TestCase
{
    public function testSuccessfulUseCaseCoordinatesOneAtomicRegistration(): void
    {
        $now = new DateTimeImmutable('2026-08-02T10:00:00Z');
        $repository = $this->createMock(IdentityRepositoryPort::class);
        $repository->expects(self::once())->method('emailExists')->with('user.one+e1@example.test')->willReturn(false);
        $repository->expects(self::once())->method('addRegistration')->willReturnCallback(
            static function (User $user, UserActionToken $token) use ($now): void {
                self::assertSame('00000000-0000-4000-8000-000000000001', $user->id);
                self::assertSame('user.one+e1@example.test', $user->email->value);
                self::assertFalse($user->isActive());
                self::assertSame($user->id, $token->userId);
                self::assertEquals($now->modify('+24 hours'), $token->expiresAt);
            },
        );
        $idempotency = $this->createMock(IdempotencyPort::class);
        $idempotency->expects(self::once())->method('begin')->willReturn(null);
        $idempotency->expects(self::once())->method('complete')->with(
            'public',
            'register',
            '11111111-1111-4111-8111-111111111111',
            201,
            self::anything(),
            null,
        );
        $outbox = $this->createMock(EmailOutboxPort::class);
        $outbox->expects(self::once())->method('enqueueActivation')->with(
            '00000000-0000-4000-8000-000000000003',
            '00000000-0000-4000-8000-000000000002',
            'user.one+e1@example.test',
            'encrypted',
            $now,
        );

        $handler = new RegisterUser(
            new PasswordPolicy(),
            $this->passwordHasher('password-hash'),
            $this->tokenCodec(),
            $this->payloadCipher('encrypted'),
            $repository,
            $idempotency,
            $outbox,
            $this->rateLimit(),
            $this->transaction(),
            $this->clock($now),
            $this->uuid(),
        );

        $result = $handler->handle(new RegisterCommand(
            'Alex Doe',
            '  User.One+E1@Example.Test  ',
            'A2345678901!',
            '11111111-1111-4111-8111-111111111111',
            '192.0.2.1',
        ));

        self::assertSame('user.one+e1@example.test', $result->user->email);
        self::assertFalse($result->user->isActive);
        self::assertTrue($result->activationRequired);
    }

    public function testExistingEmailDoesNotHashOrPersistBusinessData(): void
    {
        $now = new DateTimeImmutable('2026-08-02T10:00:00Z');
        $repository = $this->createMock(IdentityRepositoryPort::class);
        $repository->method('emailExists')->willReturn(true);
        $repository->expects(self::never())->method('addRegistration');
        $passwordHasher = $this->createMock(PasswordHashingPort::class);
        $passwordHasher->expects(self::never())->method('hash');
        $outbox = $this->createMock(EmailOutboxPort::class);
        $outbox->expects(self::never())->method('enqueueActivation');
        $idempotency = $this->createMock(IdempotencyPort::class);
        $idempotency->method('begin')->willReturn(null);
        $idempotency->expects(self::never())->method('complete');

        $handler = new RegisterUser(
            new PasswordPolicy(),
            $passwordHasher,
            $this->tokenCodec(),
            $this->payloadCipher('encrypted'),
            $repository,
            $idempotency,
            $outbox,
            $this->rateLimit(),
            $this->transaction(),
            $this->clock($now),
            $this->uuid(),
        );

        try {
            $handler->handle(new RegisterCommand(
                'Alex Doe',
                'user@example.test',
                'A2345678901!',
                '11111111-1111-4111-8111-111111111111',
                '192.0.2.1',
            ));
            self::fail('The duplicate email must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::EmailAlreadyExists, $failure->failureCode);
        }
    }

    private function passwordHasher(string $hash): PasswordHashingPort
    {
        $port = $this->createStub(PasswordHashingPort::class);
        $port->method('hash')->willReturn($hash);

        return $port;
    }

    private function tokenCodec(): ActionTokenCodecPort
    {
        $port = $this->createStub(ActionTokenCodecPort::class);
        $port->method('issue')->willReturn(new IssuedActionToken('v1.public', 'v1:digest'));

        return $port;
    }

    private function payloadCipher(string $encrypted): PayloadCipherPort
    {
        $port = $this->createStub(PayloadCipherPort::class);
        $port->method('encrypt')->willReturn($encrypted);

        return $port;
    }

    private function rateLimit(): RateLimitPort
    {
        $port = $this->createStub(RateLimitPort::class);
        $port->method('registrationRetryAfter')->willReturn(null);

        return $port;
    }

    private function transaction(): TransactionPort
    {
        $port = $this->createStub(TransactionPort::class);
        $port->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return $port;
    }

    private function clock(DateTimeImmutable $now): ClockPort
    {
        $port = $this->createStub(ClockPort::class);
        $port->method('now')->willReturn($now);

        return $port;
    }

    private function uuid(): UuidPort
    {
        $port = $this->createStub(UuidPort::class);
        $port->method('generate')->willReturnOnConsecutiveCalls(
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            '00000000-0000-4000-8000-000000000003',
        );

        return $port;
    }
}
