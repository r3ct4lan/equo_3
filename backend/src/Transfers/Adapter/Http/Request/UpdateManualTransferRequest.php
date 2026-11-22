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
        #[Assert\Regex('/^[A-Z]{3}$/D')]
        public ?string $currency = null,
        #[Assert\Regex('/^\d{1,12}(?:\.\d{1,18})?$/D')]
        public ?string $manualRate = null,
    ) {
    }
}
