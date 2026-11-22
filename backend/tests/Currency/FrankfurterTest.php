<?php

declare(strict_types=1);

namespace App\Tests\Currency;

use App\Currency\Adapter\Frankfurter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FrankfurterTest extends TestCase
{
    public function testCatalogFiltersAssetsAndReadsExactDatedQuotes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(file_get_contents(__DIR__.'/catalog.json')),
            new MockResponse(file_get_contents(__DIR__.'/rates.csv')),
        ]);
        $snapshot = (new Frankfurter($client))->fetch();
        self::assertEqualsCanonicalizing(['EUR', 'USD', 'RSD', 'HUF'], array_keys($snapshot['currencies']));
        self::assertSame('117.36', $snapshot['rates']['RSD']['value']);
        self::assertSame('2026-11-20', $snapshot['rates']['RSD']['date']);
        self::assertArrayNotHasKey('BTC', $snapshot['rates']);
    }
}
