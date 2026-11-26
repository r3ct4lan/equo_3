<?php

declare(strict_types=1);

namespace App\Debts\Adapter;

use App\Debts\Domain\ReceiptArithmetic;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

final class ExactReceiptArithmetic implements ReceiptArithmetic
{
    public function discountedShare(array $portions, int $total, int $gross): int
    {
        $sum = BigRational::of(0);
        foreach ($portions as [$amount, $assigned, $quantity]) {
            $sum = $sum->plus(BigRational::of($amount)->multipliedBy($assigned)->dividedBy($quantity));
        }

        return $sum->multipliedBy($total)->dividedBy($gross)->toScale(0, RoundingMode::Ceiling)->toInt();
    }
}
