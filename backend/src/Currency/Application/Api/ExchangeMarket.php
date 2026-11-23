<?php

declare(strict_types=1);

namespace App\Currency\Application\Api;

use App\Currency\Application\MarketSource;
use App\Currency\Application\MarketStore;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;

final readonly class ExchangeMarket
{
    public function __construct(private MarketSource $source, private MarketStore $store, private CurrencyArithmetic $arithmetic)
    {
    }

    public function refresh(): void
    {
        $this->store->save($this->source->fetch());
    }

    /** @return array<string,int> */
    public function currencies(): array
    {
        $catalog = $this->snapshot()['currencies'];

        return $catalog ?: throw new MarketUnavailable();
    }

    public function requireCurrency(string $code): void
    {
        if ('EUR' !== $code && !isset($this->currencies()[$code])) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
    }

    public function validateManualRate(string $currency, string $target, ?string $rate): void
    {
        if (null !== $rate && ($currency === $target || 1 !== preg_match('/^\d{1,12}(?:\.\d{1,18})?$/D', $rate) || '' === trim($rate, '0.'))) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
    }

    public function equivalent(int $amount, string $from, string $to, ?string $manual, ?string $rateCurrency, bool $inverseManual = false): int
    {
        if (0 === $amount || $from === $to) {
            return $amount;
        }
        $quote = $to === $rateCurrency && null !== $manual
            ? ($inverseManual ? $this->arithmetic->ratio('1', $manual) : $manual)
            : $this->quote($from, $to);

        return $this->arithmetic->convert($amount, $from, $to, $quote);
    }

    public function cardEquivalent(int $amount, string $from, string $to, ?string $manual, ?string $rateCurrency, bool $inverseManual = false): ?int
    {
        try {
            return $this->equivalent($amount, $from, $to, $manual, $rateCurrency, $inverseManual);
        } catch (MarketUnavailable) {
            return null;
        }
    }

    public function quote(string $from, string $to): string
    {
        if ($from === $to) {
            return '1';
        }
        $rates = $this->snapshot([$from, $to])['rates'];
        $base = 'EUR' === $from ? '1' : ($rates[$from]['value'] ?? throw new MarketUnavailable());
        $target = 'EUR' === $to ? '1' : ($rates[$to]['value'] ?? throw new MarketUnavailable());

        return $this->arithmetic->ratio($target, $base);
    }

    /** @param list<string> $required
     * @return array{currencies:array<string,int>,rates:array<string,array{value:string,date:string}>,fresh:bool}
     */
    private function snapshot(array $required = []): array
    {
        $stored = $this->store->read();
        $missing = array_diff($required, ['EUR'], array_keys($stored['rates']));
        if (!$stored['fresh'] || [] !== $missing) {
            try {
                $this->refresh();

                return $this->store->read();
            } catch (MarketUnavailable) {
                // A failed fetch leaves the last successfully published snapshot intact.
            }
        }

        return $stored;
    }
}
