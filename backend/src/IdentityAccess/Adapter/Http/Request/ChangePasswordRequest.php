<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http\Request;

use App\IdentityAccess\Domain\User\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangePasswordRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Value is required.', payload: ['code' => 'REQUIRED'])]
        public ?string $currentPassword = null,
        #[Assert\NotNull(message: 'Value is required.', payload: ['code' => 'REQUIRED'])]
        #[Assert\Length(min: PasswordPolicy::MIN_LENGTH, max: PasswordPolicy::MAX_LENGTH, minMessage: 'Use 8–128 characters.', maxMessage: 'Use 8–128 characters.', payload: ['code' => 'PASSWORD_POLICY_VIOLATION'])]
        #[Assert\Regex(pattern: '/\S/u', message: 'Use at least one non-space character.', payload: ['code' => 'PASSWORD_POLICY_VIOLATION'])]
        public ?string $newPassword = null,
    ) {
    }
}
