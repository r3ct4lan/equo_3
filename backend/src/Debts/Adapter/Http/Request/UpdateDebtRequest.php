<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Http\Request;

use App\Debts\Domain\Money;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateDebtRequest
{
    /** @param list<string>|null $participantIds
     * @param array<string,mixed>|null $receipt
     */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $title = null,
        public ?string $description = null,
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Range(min: 1, max: Money::MAX_MINOR_UNITS)]
        public mixed $totalAmount = null,
        #[Assert\NotNull]
        #[Assert\Count(min: 1)]
        #[Assert\All([new Assert\Type('string'), new Assert\Uuid()])]
        public ?array $participantIds = null,
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public mixed $includePayerInSplit = null,
        #[Assert\Regex('/^[A-Z]{3}$/D')]
        public ?string $currency = null,
        #[Assert\Regex('/^\d{1,12}(?:\.\d{1,18})?$/D')]
        public ?string $manualRate = null,
        public ?array $receipt = null,
    ) {
    }
}
