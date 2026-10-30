<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangeLocaleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['en', 'ru'])]
        public ?string $locale = null,
    ) {
    }
}
