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
        yield '8 code points' => ['abcde1!x'];
        yield '8 supplementary code points' => [str_repeat('😀', 6).'я1'];
        yield '128 code points' => [str_repeat('я', 126).'1!'];
        yield 'significant outer whitespace' => ['  a1😀d  '];
        yield 'Unicode letter and decimal digit' => ['пароль١!'];
        yield 'uppercase letters only' => ['ABCDEFG1!'];
        yield 'combining sequence preserved' => ["e\u{0301}abc1!z"];
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
        yield '7 code points' => ['abcd1!x'];
        yield '7 supplementary code points' => [str_repeat('😀', 5).'я1'];
        yield '129 code points' => [str_repeat('a', 127).'1!'];
        yield 'whitespace only' => [str_repeat(' ', 8)];
        yield 'digits only' => ['12345678'];
        yield 'no letter' => ['1234567!'];
        yield 'no digit' => ['Password!'];
        yield 'no special character' => ['Password1'];
        yield 'space is not special' => ['Password1 '];
        yield 'Unicode space is not special' => ["Password1\u{00A0}"];
        yield 'control is not special' => ["Password1\t"];
        yield 'combining mark is not special' => ["Password1\u{0301}"];
    }
}
