<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\User;

final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 128;

    public function assertSatisfied(string $password): void
    {
        $length = mb_strlen($password, 'UTF-8');

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || 1 !== preg_match('/\S/u', $password)) {
            throw new PasswordPolicyViolation('Password does not satisfy the configured policy.');
        }
    }
}
