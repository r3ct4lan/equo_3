<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RenameCurrentUserRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Value is required.', payload: ['code' => 'REQUIRED'])]
        #[Assert\Regex(pattern: '/\S/u', message: 'Value must contain a non-whitespace character.', payload: ['code' => 'REQUIRED'])]
        public ?string $name = null,
    ) {
    }
}
