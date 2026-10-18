<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Domain\User;

use App\IdentityAccess\Domain\User\PasswordPolicy;
use App\IdentityAccess\Domain\User\PasswordPolicyViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    #[DataProvider('validPasswordProvider')]
    public function testValidBoundariesAreAcceptedWithoutTrimming(string $password): void
    {
        (new PasswordPolicy())->assertSatisfied($password);

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function validPasswordProvider(): iterable
    {
        yield '8 code points' => [str_repeat('a', 8)];
        yield '8 supplementary code points' => [str_repeat('😀', 8)];
        yield '128 code points' => [str_repeat('я', 128)];
        yield 'significant outer whitespace' => ['  ab😀d  '];
    }

    #[DataProvider('invalidPasswordProvider')]
    public function testInvalidBoundariesAreRejected(string $password): void
    {
        $this->expectException(PasswordPolicyViolation::class);

        (new PasswordPolicy())->assertSatisfied($password);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPasswordProvider(): iterable
    {
        yield '7 code points' => [str_repeat('a', 7)];
        yield '7 supplementary code points' => [str_repeat('😀', 7)];
        yield '129 code points' => [str_repeat('a', 129)];
        yield 'whitespace only' => [str_repeat(' ', 8)];
    }
}
