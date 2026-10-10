<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\PasswordChange;

use SensitiveParameter;

final readonly class ChangePasswordCommand
{
    public function __construct(
        public string $userId,
        #[SensitiveParameter] public string $currentPassword,
        #[SensitiveParameter] public string $newPassword,
    ) {
    }
}
