<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http\Request;

use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class LinkEmailRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 254)]
        public ?string $email = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        #[SensitiveParameter]
        public ?string $password = null,
    ) {
    }
}
