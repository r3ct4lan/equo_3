<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Value is required.', payload: ['code' => 'REQUIRED'])]
        #[Assert\Regex(pattern: '/\S/u', message: 'Value must contain a non-whitespace character.', payload: ['code' => 'REQUIRED'])]
        public ?string $name = null,
        #[Assert\NotBlank(message: 'Value is required.', payload: ['code' => 'REQUIRED'])]
        #[Assert\Email(message: 'Email has invalid format.', normalizer: 'trim', payload: ['code' => 'INVALID_EMAIL'])]
        public ?string $email = null,
        #[Assert\NotBlank(message: 'Value is required.', payload: ['code' => 'REQUIRED'])]
        public ?string $password = null,
    ) {
    }
}
