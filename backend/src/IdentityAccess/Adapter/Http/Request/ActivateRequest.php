<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ActivateRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Value is required.', payload: ['code' => 'REQUIRED'])]
        public ?string $token = null,
    ) {
    }
}
