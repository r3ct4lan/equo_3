<?php

declare(strict_types=1);

namespace App\Debts\Domain;

use InvalidArgumentException;

final readonly class Money
{
    public const int MAX_CENTS = 2147483647;

    public function __construct(public int $cents)
    {
        if ($cents < 1 || $cents > self::MAX_CENTS) {
            throw new InvalidArgumentException('Amount must be positive integer eurocents within storage range.');
        }
    }

    public function share(int $count): int
    {
        if ($count < 1) {
            throw new InvalidArgumentException('A split needs participants.');
        }

        return intdiv($this->cents, $count) + (0 === $this->cents % $count ? 0 : 1);
    }
}
