<?php

declare(strict_types=1);

namespace App\Currency\Adapter;

use App\Currency\Application\Api\CurrencyArithmetic;
use App\Currency\Domain\CurrencyPrecision;
use Brick\Math\BigRational;

final class ExactCurrencyArithmetic implements CurrencyArithmetic
{
    public function ratio(string $target, string $base): string
    {
        return BigRational::of($target)->dividedBy($base)->toString();
    }

    public function convert(int $amount, string $from, string $to, string $quote): int
    {
        return ExchangeRate::fromDecimal($quote, CurrencyPrecision::DIGITS[$from], CurrencyPrecision::DIGITS[$to])->ceil($amount);
    }

    public function settle(int $amount, int $remaining, string $from, string $to, string $quote): array
    {
        return ExchangeRate::fromDecimal($quote, CurrencyPrecision::DIGITS[$from], CurrencyPrecision::DIGITS[$to])->settle($amount, $remaining);
    }
}
