<?php

declare(strict_types=1);

namespace App\Currency\Adapter;

use App\Currency\Application\Api\MarketUnavailable;
use App\Currency\Application\MarketSource;
use App\Currency\Domain\CurrencyPrecision;
use App\Currency\Domain\FeedPolicy;
use App\Currency\Domain\FeedUpdate;
use App\Currency\Domain\MarketFeed;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class Frankfurter implements MarketSource
{
    private const int MAX_FRESH_SECONDS = 3600;

    public function __construct(private HttpClientInterface $http)
    {
    }

    public function fetch(MarketFeed $feed, FeedPolicy $previous): FeedUpdate
    {
        $path = MarketFeed::Catalog === $feed ? 'currencies' : 'rates.csv?base=EUR';
        $headers = array_filter(['Cache-Control' => 'no-cache', 'If-None-Match' => $previous->etag, 'If-Modified-Since' => $previous->lastModified]);
        $start = time();
        try {
            $response = $this->http->request('GET', 'https://api.frankfurter.dev/v2/'.$path, ['headers' => $headers, 'timeout' => 2.0, 'max_duration' => 3.0]);
            $status = $response->getStatusCode();
            $fields = $response->getHeaders(false);
            $unchanged = 304 === $status;
            if ($unchanged && (null !== $previous->etag || null !== $previous->lastModified)) {
                $data = null;
            } elseif (200 === $status) {
                $data = MarketFeed::Catalog === $feed ? $this->catalog($response->toArray()) : $this->rates($response->getContent());
            } else {
                throw new MarketUnavailable();
            }
        } catch (ExceptionInterface) {
            throw new MarketUnavailable();
        }

        $now = time();
        $control = HeaderUtils::combine(HeaderUtils::split(implode(',', $fields['cache-control'] ?? []), ',='));
        $date = strtotime($fields['date'][0] ?? '') ?: $now;
        $age = max(0, $now - $date, (int) ($fields['age'][0] ?? 0) + $now - $start);
        $maxAge = $control['s-maxage'] ?? $control['max-age'] ?? null;
        $lifetime = match (true) {
            isset($control['no-cache']) => 0,
            null !== $maxAge => is_string($maxAge) && ctype_digit($maxAge) ? (int) $maxAge : 0,
            isset($fields['expires'][0]) => max(0, (strtotime($fields['expires'][0]) ?: 0) - $date),
            $unchanged => $previous->lifetime,
            default => 0,
        };
        if (($lifetime > 0 && $age >= $lifetime) || (0 === $lifetime && (int) ($fields['age'][0] ?? 0) > 0)) {
            // An intermediary may serve an already-expired body during an outage.
            throw new MarketUnavailable();
        }
        $policy = new FeedPolicy(
            $now + min(self::MAX_FRESH_SECONDS, max(0, $lifetime - $age)),
            $lifetime,
            $fields['etag'][0] ?? ($unchanged ? $previous->etag : null),
            $fields['last-modified'][0] ?? ($unchanged ? $previous->lastModified : null),
        );

        return new FeedUpdate($data, $policy, !isset($control['no-store']) && !isset($control['private']));
    }

    /** @param array<mixed> $rows
     * @return array<string,int>
     */
    private function catalog(array $rows): array
    {
        $currencies = [];
        foreach ($rows as $row) {
            $code = $row['iso_code'] ?? '';
            if ('currency' === ($row['type'] ?? null) && isset(CurrencyPrecision::DIGITS[$code])) {
                $currencies[$code] = CurrencyPrecision::DIGITS[$code];
            }
        }
        if (!isset($currencies['EUR'])) {
            throw new MarketUnavailable();
        }
        ksort($currencies);

        return $currencies;
    }

    /** @return array<string,array{value:string,date:string}> */
    private function rates(string $csv): array
    {
        $lines = explode("\n", trim($csv));
        if ('date,base,quote,rate' !== trim(array_shift($lines))) {
            throw new MarketUnavailable();
        }
        $rates = [];
        foreach ($lines as $line) {
            $fields = explode(',', trim($line));
            if (4 !== count($fields)) {
                continue;
            }
            [$date, $baseCode, $code, $value] = $fields;
            if ('EUR' === $baseCode && isset(CurrencyPrecision::DIGITS[$code])
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                && preg_match('/^\d{1,16}(?:\.\d{1,18})?$/D', $value)
                && '' !== trim($value, '0.')) {
                $rates[$code] = ['value' => $value, 'date' => $date];
            }
        }
        if ([] === $rates) {
            throw new MarketUnavailable();
        }

        return $rates;
    }
}
