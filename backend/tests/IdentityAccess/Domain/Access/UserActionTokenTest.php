<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Domain\Access;

use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserActionTokenTest extends TestCase
{
    #[DataProvider('invalidLifetimes')]
    public function testTokenCannotExpireAtOrBeforeItsCreation(string $expiresAt): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('An action token must expire after it is created.');

        new UserActionToken('token-id', 'user-id', 'synthetic-digest', UserActionTokenPurpose::ActivateAccount,
            new DateTimeImmutable('2026-10-29T12:00:00Z'), new DateTimeImmutable($expiresAt));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLifetimes(): iterable
    {
        yield 'same instant in another zone' => ['2026-10-29T14:00:00+02:00'];
        yield 'earlier' => ['2026-10-29T11:59:59Z'];
    }
}
