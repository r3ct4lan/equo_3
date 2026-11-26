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
     * @param array<string,mixed>      $receipt
     * @param list<string>             $participants
     * @param array<string,mixed>|null $saved
     *
     * @return array<string,int>
     */
    public function shares(array $receipt, int $total, array $participants, ?array $saved = null): array
    {
        $lines = $receipt['lines'] ?? null;
        if (!is_string($receipt['uploadId'] ?? null) || !CreateDebtCommand::isUuid($receipt['uploadId']) || !is_array($lines) || !array_is_list($lines) || count($lines) > 300 || !is_int($receipt['discount'] ?? null) || !is_array($receipt['remainder'] ?? null) || [] !== array_diff(array_keys($receipt), ['uploadId', 'lines', 'discount', 'remainder', 'remainderSeats'])) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
        foreach ($lines as $i => &$line) {
            if (!is_array($line) || !is_string($line['name'] ?? null) || mb_strlen($line['name']) > 500 || [] !== array_diff(array_keys($line), ['name', 'quantity', 'unitPrice', 'amount', 'assignments', 'mergedSeats'])) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
            if (isset($line['mergedSeats'])) {
                $this->recoverSeats($line, $line['mergedSeats'], $saved['lines'][$i]['mergedSeats'] ?? null);
            }
            $price = $line['unitPrice'] ?? null;
            if (null !== $price && (!is_int($price) || $price < 0 || $price > Money::MAX_MINOR_UNITS)) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
        }
        unset($line);
        $remainder = $receipt['remainder'];
        if (isset($receipt['remainderSeats'])) {
            $row = ['assignments' => $remainder, 'amount' => $total - array_sum(array_column($lines, 'amount'))];
            $this->recoverSeats($row, $receipt['remainderSeats'], $saved['remainderSeats'] ?? null);
            $lines[] = $row;
            $remainder = [];
        }
        try {
            return $this->split->calculate($total, $lines, $receipt['discount'], $remainder, $participants);
        } catch (InvalidArgumentException) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
    }

    /** @param array<string,mixed> $row */
    private function recoverSeats(array &$row, mixed $seats, mixed $saved): void
    {
        if (!is_array($seats) || !is_array($saved) || !is_array($row['assignments'] ?? null)) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
        ksort($seats);
        ksort($saved);
        if ($seats !== $saved || array_diff_key($seats, $row['assignments'])) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
        $total = 0;
        foreach ($row['assignments'] as $id => $quantity) {
            if (null !== $quantity) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
            $weight = $seats[$id] ?? 1;
            $total += $weight;
            $row['assignments'][$id] = (string) $weight;
        }
        $row['quantity'] = (string) $total;
    }
}
