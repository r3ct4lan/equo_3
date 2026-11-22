<?php

declare(strict_types=1);

namespace App\Debts\Domain;

use InvalidArgumentException;

final readonly class Money
{
    public const int MAX_MINOR_UNITS = 2147483647;

    public function __construct(public int $minorUnits, public string $currency = 'EUR')
    {
        if (1 !== preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Invalid currency code.');
        }
        if ($minorUnits < 1 || $minorUnits > self::MAX_MINOR_UNITS) {
            throw new InvalidArgumentException('Amount must be positive integer minor units within storage range.');
        }
    }

    public function share(int $count): int
    {
        if ($count < 1) {
            throw new InvalidArgumentException('A split needs participants.');
        }

        return intdiv($this->minorUnits, $count) + (0 === $this->minorUnits % $count ? 0 : 1);
    }
}
