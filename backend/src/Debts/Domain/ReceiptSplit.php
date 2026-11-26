<?php

declare(strict_types=1);

namespace App\Debts\Domain;

use InvalidArgumentException;

final readonly class ReceiptSplit
{
    public function __construct(private ReceiptArithmetic $math)
    {
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>       $remainder
     * @param list<string>              $people
     *
     * @return array<string,int>
     */
    public function calculate(int $total, array $rows, int $discount, array $remainder, array $people): array
    {
        if ($total < 1 || $total > Money::MAX_MINOR_UNITS || $discount < 0 || $discount > Money::MAX_MINOR_UNITS || count($rows) > 300) {
            throw new InvalidArgumentException('Invalid receipt total.');
        }
        $sum = 0;
        $portions = [];
        foreach ($rows as $row) {
            $amount = $row['amount'] ?? null;
            if (!is_int($amount) || $amount < 0 || $amount > Money::MAX_MINOR_UNITS || !is_array($row['assignments'] ?? null)) {
                throw new InvalidArgumentException('Invalid receipt line.');
            }
            $sum += $amount;
            $this->assign($portions, $amount, self::quantity($row['quantity'] ?? null), $row['assignments'], $people);
        }
        $gross = $total + $discount;
        if ($sum < $total && 0 === $discount) {
            $this->assign($portions, $total - $sum, 1000000, $remainder, $people);
        } elseif ($sum !== $gross || [] !== $remainder) {
            throw new InvalidArgumentException('Reconcile printed total and discount.');
        }

        return array_map(fn (array $parts): int => $this->math->discountedShare($parts, $total, $gross), $portions);
    }

    /**
     * @param array<string,list<array{int,int,int}>> $portions
     * @param array<mixed>                           $assignments
     * @param list<string>                           $people
     */
    private function assign(array &$portions, int $amount, int $quantity, array $assignments, array $people): void
    {
        if ([] === $assignments || count($assignments) > count($people)) {
            throw new InvalidArgumentException('Assign every purchase.');
        }
        $equal = null === reset($assignments);
        $denominator = $equal ? count($assignments) : $quantity;
        $used = 0;
        foreach ($assignments as $user => $value) {
            if (!in_array($user, $people, true) || $equal !== (null === $value)) {
                throw new InvalidArgumentException('Invalid assignment.');
            }
            $units = $equal ? 1 : self::quantity($value);
            if ($units > $denominator - $used) {
                throw new InvalidArgumentException('Too many units assigned.');
            }
            $used += $units;
            $portions[$user][] = [$amount, $units, $denominator];
        }
        if ($used !== $denominator) {
            throw new InvalidArgumentException('Unassigned quantity.');
        }
    }

    private static function quantity(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^(\d{1,9})(?:\.(\d{1,6}))?$/D', $value, $matches)) {
            throw new InvalidArgumentException('Invalid quantity.');
        }
        $units = (int) ($matches[1].str_pad($matches[2] ?? '', 6, '0'));

        return $units ?: throw new InvalidArgumentException('Quantity must be positive.');
    }
}
