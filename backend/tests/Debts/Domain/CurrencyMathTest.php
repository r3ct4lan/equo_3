<?php

declare(strict_types=1);

namespace App\Tests\Debts\Domain;

use App\Currency\Domain\ExchangeRate;
use App\Debts\Domain\Money;
use PHPUnit\Framework\TestCase;

final class CurrencyMathTest extends TestCase
{
    public function testRepaymentThreshold(): void
    {
        $rate = ExchangeRate::fromDecimal('125', 2, 2);
        self::assertSame(961, $rate->inverse()->ceil(120050));
        self::assertSame(['credited' => 120000, 'excess' => 0], $rate->settle(960, 120050));
        self::assertSame(['credited' => 120050, 'excess' => 0], $rate->settle(961, 120050));
        self::assertSame(['credited' => 120050, 'excess' => 1], $rate->settle(962, 120050));
    }

    public function testCurrencyPrecisionAndLargeProducts(): void
    {
        foreach (['JPY', 'EUR', 'KWD'] as $currency) {
            $money = new Money(1001, $currency);
            self::assertSame($currency, $money->currency);
            self::assertSame(334, $money->share(3));
        }
        $rate = ExchangeRate::fromDecimal('0.00234567891234', 0, 3);
        self::assertSame(2346, $rate->ceil(1000));
        self::assertSame(2147483647, ExchangeRate::fromDecimal('1.000000000000000000', 2, 2)->ceil(2147483647));
    }
}
