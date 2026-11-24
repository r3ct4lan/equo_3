<?php

declare(strict_types=1);

namespace App\Tests\Fixture\E2e;

use App\Currency\Application\MarketSource;
use App\Currency\Domain\FeedPolicy;
use App\Currency\Domain\FeedUpdate;
use App\Currency\Domain\MarketFeed;

final class FixedMarket implements MarketSource
{
    public function fetch(MarketFeed $feed, FeedPolicy $previous): FeedUpdate
    {
        $values = ['USD' => '1.25', 'RSD' => '125', 'HUF' => '400', 'JPY' => '160', 'KWD' => '0.33'];
        $rates = [];
        foreach ($values as $code => $value) {
            $rates[$code] = ['value' => $value, 'date' => '2026-11-20'];
        }

        return new FeedUpdate(MarketFeed::Catalog === $feed ? ['EUR' => 2, 'CAD' => 2, 'USD' => 2, 'RSD' => 2, 'HUF' => 2, 'JPY' => 0, 'KWD' => 3] : $rates, new FeedPolicy(time() + 3600, 3600));
    }
}
