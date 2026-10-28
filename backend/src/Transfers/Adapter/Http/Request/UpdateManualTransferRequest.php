<?php

declare(strict_types=1);

namespace App\Transfers\Adapter\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateManualTransferRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $senderId = null,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $receiverId = null,
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Range(min: 1, max: 2147483647)]
        public mixed $amount = null,
        #[Assert\Uuid]
        public ?string $relatedTransferId = null,
    ) {
    }
}
