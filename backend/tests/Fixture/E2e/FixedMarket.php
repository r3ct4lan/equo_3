<?php

declare(strict_types=1);

namespace App\Tests\Fixture\E2e;

use App\Currency\Application\MarketSource;

final class FixedMarket implements MarketSource
{
    public function fetch(): array
    {
        $values = ['USD' => '1.25', 'RSD' => '125', 'HUF' => '400', 'JPY' => '160', 'KWD' => '0.33'];
        $rates = [];
        foreach ($values as $code => $value) {
            $rates[$code] = ['value' => $value, 'date' => '2026-11-20'];
        }

        return ['currencies' => ['EUR' => 2, 'USD' => 2, 'RSD' => 2, 'HUF' => 2, 'JPY' => 0, 'KWD' => 3], 'rates' => $rates];
    }
}
