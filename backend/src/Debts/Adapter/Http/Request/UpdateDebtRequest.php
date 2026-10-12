<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Http\Request;

use App\Debts\Domain\Money;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateDebtRequest
{
    /** @param list<string>|null $participantIds */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $title = null,
        public ?string $description = null,
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Range(min: 1, max: Money::MAX_CENTS)]
        public mixed $totalAmount = null,
        #[Assert\NotNull]
        #[Assert\Count(min: 1)]
        #[Assert\All([new Assert\Type('string'), new Assert\Uuid()])]
        public ?array $participantIds = null,
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public mixed $includePayerInSplit = null,
    ) {
    }
}
