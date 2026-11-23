<?php

declare(strict_types=1);

namespace App\Currency\Adapter;

use App\Currency\Application\Api\MarketUnavailable;
use App\Currency\Application\MarketSource;
use App\Currency\Domain\CurrencyPrecision;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class Frankfurter implements MarketSource
{
    public function __construct(private HttpClientInterface $http)
    {
    }

    public function fetch(): array
    {
        $base = 'https://api.frankfurter.dev/v2/';
        $options = ['timeout' => 2.0, 'max_duration' => 3.0];
        try {
            $catalog = $this->http->request('GET', $base.'currencies', $options);
            $quotes = $this->http->request('GET', $base.'rates.csv?base=EUR', $options);
            $currencies = [];
            foreach ($catalog->toArray() as $row) {
                $code = $row['iso_code'] ?? '';
                if ('currency' === ($row['type'] ?? null) && isset(CurrencyPrecision::DIGITS[$code])) {
                    $currencies[$code] = CurrencyPrecision::DIGITS[$code];
                }
            }
            $lines = explode("\n", trim($quotes->getContent()));
        } catch (ExceptionInterface) {
            throw new MarketUnavailable();
        }
        if (!isset($currencies['EUR']) || 'date,base,quote,rate' !== trim(array_shift($lines))) {
            throw new MarketUnavailable();
        }
        $rates = [];
        foreach ($lines as $line) {
            $fields = explode(',', trim($line));
            if (4 !== count($fields)) {
                continue;
            }
            [$date, $baseCode, $code, $value] = $fields;
            if ('EUR' === $baseCode && isset($currencies[$code])
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                && preg_match('/^\d{1,16}(?:\.\d{1,18})?$/D', $value)
                && '' !== trim($value, '0.')) {
                $rates[$code] = ['value' => $value, 'date' => $date];
            }
        }
        if ([] === $rates) {
            throw new MarketUnavailable();
        }
        ksort($currencies);

        return ['currencies' => $currencies, 'rates' => $rates];
    }
}
