<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Domain\User;

use App\IdentityAccess\Domain\User\EmailAddress;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testEmailIsTrimmedAndLowercasedWithoutProviderSpecificChanges(): void
    {
        $email = new EmailAddress('  User.One+E1@Example.Test  ');

        self::assertSame('user.one+e1@example.test', $email->value);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EmailAddress('not-an-email');
    }
}
