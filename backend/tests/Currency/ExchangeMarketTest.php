<?php

declare(strict_types=1);

namespace App\Tests\Currency;

use App\Currency\Adapter\DoctrineMarketStore;
use App\Currency\Application\Api\MarketUnavailable;
use App\Currency\Application\MarketSource;
use App\Currency\Domain\FeedPolicy;
use App\Currency\Domain\FeedUpdate;
use App\Currency\Domain\MarketFeed;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExchangeMarketTest extends KernelTestCase
{
    public function testFailedRevalidationUsesStoredRatesUntilTheNextRetry(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $db->executeStatement('DELETE FROM currency_market');
            // A pre-migration row can have a recent timestamp but no HTTP freshness metadata.
            $db->insert('currency_market', ['id' => 1, 'currencies' => '{}', 'rates' => '{"RSD":{"value":"90","date":"2026-11-23"}}', 'refreshed_at' => 'now']);
            $source = $this->createMock(MarketSource::class);
            $next = 0;
            $a = ['RSD' => ['date' => '2026-11-24', 'value' => '120']];
            $b = ['USD' => ['date' => '2026-11-25', 'value' => '1.10']];
            $fresh = new FeedPolicy(time() + 120, 120, '"A"');
            $source->expects(self::exactly(4))->method('fetch')->willReturnCallback(static function (MarketFeed $feed, FeedPolicy $previous) use (&$next, $fresh, $a, $b) {
                self::assertSame(MarketFeed::Rates, $feed);
                if ($next > 0) {
                    self::assertSame('"A"', $previous->etag);
                }

                return match (++$next) {
                    1 => new FeedUpdate($a, $fresh),
                    2 => new FeedUpdate(null, $fresh),
                    3 => throw new MarketUnavailable(),
                    default => new FeedUpdate($b, new FeedPolicy(time() + 120, 120, '"B"')),
                };
            });
            $store = new DoctrineMarketStore($db, $source);
            $expire = static fn () => $db->executeStatement("UPDATE currency_market SET cache_policy = jsonb_set(cache_policy, '{rates,expiresAt}', '0')");
            self::assertSame($a, $store->rates());
            self::assertSame($a, $store->rates());
            $expire();
            self::assertSame($a, $store->rates());
            self::assertSame($a, $store->rates());
            $expire();
            self::assertSame($a, $store->rates());
            self::assertSame($a, $store->rates());
            self::assertSame(3, $next);

            $db->executeStatement("UPDATE currency_market SET cache_policy = jsonb_set(cache_policy, '{rates,retryAt}', '0')");
            self::assertSame($b, $store->rates());
        } finally {
            $db->rollBack();
        }
    }

    public function testColdCacheKeepsTheRetryDelayWhenTheProviderIsUnavailable(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $db->executeStatement('DELETE FROM currency_market');
            $source = $this->createMock(MarketSource::class);
            $source->expects(self::once())->method('fetch')->willThrowException(new MarketUnavailable());
            $store = new DoctrineMarketStore($db, $source);
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                try {
                    $store->rates();
                    self::fail('A cold cache cannot supply a quote.');
                } catch (MarketUnavailable) {
                    // The second request must reuse the failed attempt.
                }
            }
        } finally {
            $db->rollBack();
        }
    }
}
