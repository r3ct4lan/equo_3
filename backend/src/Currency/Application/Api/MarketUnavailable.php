<?php

declare(strict_types=1);

namespace App\Currency\Application\Api;

use RuntimeException;

final class MarketUnavailable extends RuntimeException
{
}
