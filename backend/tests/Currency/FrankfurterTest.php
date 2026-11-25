<?php

declare(strict_types=1);

namespace App\Tests\Currency;

use App\Currency\Adapter\Frankfurter;
use App\Currency\Application\Api\MarketUnavailable;
use App\Currency\Domain\FeedPolicy;
use App\Currency\Domain\MarketFeed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FrankfurterTest extends TestCase
{
    public function testCatalogAndRatesHaveIndependentPoliciesAndValidators(): void
    {
        $modified = 'Fri, 20 Nov 2026 10:00:00 GMT';
        $responses = [
            new MockResponse((string) file_get_contents(__DIR__.'/catalog.json'), ['response_headers' => ['Last-Modified: '.$modified, 'Cache-Control: max-age=9000']]),
            new MockResponse((string) file_get_contents(__DIR__.'/rates.csv'), ['response_headers' => ['ETag: "rates-A"', 'Cache-Control: public, max-age=120', 'Age: 90']]),
            $notModified = new MockResponse('', ['http_code' => 304, 'response_headers' => ['Cache-Control: max-age=600']]),
            $catalogUnchanged = new MockResponse('', ['http_code' => 304]),
        ];
        $source = new Frankfurter(new MockHttpClient($responses));
        $start = time();
        $catalog = $source->fetch(MarketFeed::Catalog, new FeedPolicy());
        self::assertEqualsCanonicalizing(['EUR', 'USD', 'RSD', 'HUF'], array_keys($catalog->data ?? []));
        self::assertEqualsWithDelta($start + 3600, $catalog->policy->expiresAt, 1);
        $quotes = $source->fetch(MarketFeed::Rates, new FeedPolicy());
        self::assertSame(['value' => '117.36', 'date' => '2026-11-20'], $quotes->data['RSD'] ?? null);
        self::assertArrayNotHasKey('BTC', $quotes->data ?? []);
        self::assertEqualsWithDelta($start + 30, $quotes->policy->expiresAt, 1);

        $validated = $source->fetch(MarketFeed::Rates, $quotes->policy);
        self::assertNull($validated->data);
        self::assertEqualsWithDelta($start + 600, $validated->policy->expiresAt, 1);
        self::assertContains('If-None-Match: "rates-A"', $notModified->getRequestOptions()['headers']);
        $source->fetch(MarketFeed::Catalog, $catalog->policy);
        self::assertContains('If-Modified-Since: '.$modified, $catalogUnchanged->getRequestOptions()['headers']);
    }

    public function testExpiredResponseFromIntermediaryIsRejected(): void
    {
        $source = new Frankfurter(new MockHttpClient(new MockResponse((string) file_get_contents(__DIR__.'/rates.csv'), ['response_headers' => ['Cache-Control: max-age=60, stale-if-error=86400', 'Date: '.gmdate(DATE_RFC7231, time() - 120)]])));
        $this->expectException(MarketUnavailable::class);
        $source->fetch(MarketFeed::Rates, new FeedPolicy());
    }

    public function testNoStoreIsUsableOnlyForTheRequestAndMissingTtlRequiresRevalidation(): void
    {
        $body = (string) file_get_contents(__DIR__.'/rates.csv');
        $source = new Frankfurter(new MockHttpClient([
            new MockResponse($body, ['response_headers' => ['Cache-Control: no-store']]), new MockResponse($body),
        ]));
        self::assertFalse($source->fetch(MarketFeed::Rates, new FeedPolicy())->storeable);
        self::assertLessThanOrEqual(time() + 1, $source->fetch(MarketFeed::Rates, new FeedPolicy())->policy->expiresAt);
    }
}
