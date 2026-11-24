<?php

declare(strict_types=1);

namespace App\Currency\Application;

use App\Currency\Domain\FeedPolicy;
use App\Currency\Domain\FeedUpdate;
use App\Currency\Domain\MarketFeed;

interface MarketSource
{
    public function fetch(MarketFeed $feed, FeedPolicy $previous): FeedUpdate;
}
