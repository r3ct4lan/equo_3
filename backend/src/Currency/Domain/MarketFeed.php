<?php

declare(strict_types=1);

namespace App\Currency\Domain;

enum MarketFeed: string
{
    case Catalog = 'currencies';
    case Rates = 'rates';
}
