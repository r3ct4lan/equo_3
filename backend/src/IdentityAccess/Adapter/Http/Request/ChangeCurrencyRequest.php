<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangeCurrencyRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/^[A-Z]{3}$/D')]
        public ?string $currency = null,
    ) {
    }
}
