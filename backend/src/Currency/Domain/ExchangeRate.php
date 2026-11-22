<?php

declare(strict_types=1);

namespace App\Currency\Domain;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final readonly class ExchangeRate
{
    private function __construct(private BigRational $minorUnitRatio)
    {
    }

    /** The quoted value is in target major units per one source major unit. */
    public static function fromDecimal(string $quote, int $sourceDigits, int $targetDigits): self
    {
        if ($sourceDigits < 0 || $sourceDigits > 4 || $targetDigits < 0 || $targetDigits > 4) {
            throw new InvalidArgumentException('Invalid minor unit precision.');
        }
        $ratio = BigRational::of($quote);
        if (!$ratio->isPositive()) {
            throw new InvalidArgumentException('Exchange rate must be positive.');
        }

        return new self($ratio->multipliedBy(10 ** $targetDigits)->dividedBy(10 ** $sourceDigits));
    }

    public function inverse(): self
    {
        return new self($this->minorUnitRatio->reciprocal());
    }

    public function ceil(int $sourceUnits): int
    {
        return $this->minorUnitRatio->multipliedBy($sourceUnits)->toScale(0, RoundingMode::Ceiling)->toInt();
    }

    /** @return array{credited:int,excess:int} */
    public function settle(int $paid, int $remaining): array
    {
        if ($paid < 0 || $remaining < 0) {
            throw new InvalidArgumentException('Settlement amounts must be nonnegative.');
        }
        $converted = $this->minorUnitRatio->multipliedBy($paid);
        if ($converted->isLessThan($remaining)) {
            return ['credited' => $converted->getIntegralPart()->toInt(), 'excess' => 0];
        }

        return ['credited' => $remaining, 'excess' => $paid - $this->inverse()->ceil($remaining)];
    }
}
