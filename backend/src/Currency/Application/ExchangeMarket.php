<?php

declare(strict_types=1);

namespace App\Currency\Application;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use Brick\Math\BigRational;

final readonly class ExchangeMarket
{
    public function __construct(private MarketSource $source, private MarketStore $store)
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

    public function quote(string $from, string $to): string
    {
        if ($from === $to) {
            return '1';
        }
        $rates = $this->snapshot([$from, $to])['rates'];
        $base = 'EUR' === $from ? '1' : ($rates[$from]['value'] ?? throw new MarketUnavailable());
        $target = 'EUR' === $to ? '1' : ($rates[$to]['value'] ?? throw new MarketUnavailable());

        return BigRational::of($target)->dividedBy($base)->toString();
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
