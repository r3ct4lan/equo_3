<?php

declare(strict_types=1);

namespace App\Currency\Application\Api;

interface CurrencyArithmetic
{
    public function ratio(string $target, string $base): string;

    public function convert(int $amount, string $from, string $to, string $quote): int;

    /** @return array{credited:int,excess:int} */
    public function settle(int $amount, int $remaining, string $from, string $to, string $quote): array;
}
