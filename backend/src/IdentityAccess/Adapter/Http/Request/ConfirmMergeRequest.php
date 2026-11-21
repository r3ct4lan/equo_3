<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConfirmMergeRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/\A[a-f0-9]{64}\z/D')]
        public string $version = '',
    ) {
    }
}
