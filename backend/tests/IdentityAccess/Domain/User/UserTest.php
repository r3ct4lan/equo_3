<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Domain\User;

use App\IdentityAccess\Domain\User\EmailAddress;
use App\IdentityAccess\Domain\User\User;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testRegistrationCreatesInactiveUserAndActivationIsAOneWayTransition(): void
    {
        $user = User::register(
            '00000000-0000-4000-8000-000000000001',
            'Ada',
            new EmailAddress('ada@example.test'),
            'hash',
            new DateTimeImmutable('2026-08-02T10:00:00Z'),
        );

        self::assertFalse($user->isActive());
        $user->activate();
        self::assertTrue($user->isActive());

        try {
            $user->activate();
            self::fail('A second activation must fail.');
        } catch (DomainException) {
            self::assertTrue($user->isActive(), 'A failed transition must not partially change state.');
        }
    }

    public function testBlankNameIsRejected(): void
    {
        $this->expectException(DomainException::class);

        User::register(
            '00000000-0000-4000-8000-000000000001',
            " \t ",
            new EmailAddress('ada@example.test'),
            'hash',
            new DateTimeImmutable('2026-08-02T10:00:00Z'),
        );
    }
}
