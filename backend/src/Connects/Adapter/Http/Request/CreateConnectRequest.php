<?php

declare(strict_types=1);

namespace App\Connects\Adapter\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateConnectRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $otherUserId = null,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $sharedDebtId = null,
    ) {
    }
}
