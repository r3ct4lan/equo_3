<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Debts\Domain\Money;
use App\Debts\Domain\ReceiptSplit;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use InvalidArgumentException;

final readonly class ReceiptAllocation
{
    public function __construct(private ReceiptSplit $split)
    {
    }

    /**
     * @param array<string,mixed> $receipt
     * @param list<string>        $participants
     *
     * @return array<string,int>
     */
    public function shares(array $receipt, int $total, array $participants): array
    {
        $lines = $receipt['lines'] ?? null;
        if (!is_string($receipt['uploadId'] ?? null) || !CreateDebtCommand::isUuid($receipt['uploadId']) || !is_array($lines) || !array_is_list($lines) || count($lines) > 300 || !is_int($receipt['discount'] ?? null) || !is_array($receipt['remainder'] ?? null) || [] !== array_diff(array_keys($receipt), ['uploadId', 'lines', 'discount', 'remainder'])) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
        foreach ($lines as $line) {
            if (!is_array($line) || !is_string($line['name'] ?? null) || mb_strlen($line['name']) > 500 || [] !== array_diff(array_keys($line), ['name', 'quantity', 'unitPrice', 'amount', 'assignments'])) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
            $price = $line['unitPrice'] ?? null;
            if (null !== $price && (!is_int($price) || $price < 0 || $price > Money::MAX_MINOR_UNITS)) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
        }
        try {
            return $this->split->calculate($total, $lines, $receipt['discount'], $receipt['remainder'], $participants);
        } catch (InvalidArgumentException) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
    }
}
