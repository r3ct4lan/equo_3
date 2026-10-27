<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\User;

final class PasswordPolicy
{
    public const int MIN_LENGTH = 8;
    public const int MAX_LENGTH = 128;
    public const string LETTER_PATTERN = '/\p{L}/u';
    public const string DIGIT_PATTERN = '/\p{Nd}/u';
    public const string SPECIAL_CHARACTER_PATTERN = '/[\p{P}\p{S}]/u';

    public function assertSatisfied(string $password): void
    {
        $length = mb_strlen($password, 'UTF-8');

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH
            || 1 !== preg_match(self::LETTER_PATTERN, $password)
            || 1 !== preg_match(self::DIGIT_PATTERN, $password)
            || 1 !== preg_match(self::SPECIAL_CHARACTER_PATTERN, $password)) {
            throw new PasswordPolicyViolation('Password does not satisfy the configured policy.');
        }
    }
}
