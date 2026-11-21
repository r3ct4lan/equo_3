<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http\Request;

use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class TelegramCredentialRequest
{
    public function __construct(
        #[Assert\Length(max: 8192)]
        #[SensitiveParameter]
        public ?string $initData = null,
        #[Assert\Length(max: 512)]
        #[SensitiveParameter]
        public ?string $currentPassword = null,
    ) {
    }
}
