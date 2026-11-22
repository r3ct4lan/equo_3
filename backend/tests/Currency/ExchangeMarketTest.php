<?php

declare(strict_types=1);

namespace App\Tests\Currency;

use App\Currency\Adapter\DoctrineMarketStore;
use App\Currency\Application\Api\ExchangeMarket;
use App\Currency\Application\Api\MarketUnavailable;
use App\Currency\Application\MarketSource;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExchangeMarketTest extends KernelTestCase
{
    public function testPartialRefreshAndOfflineFallback(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $db->executeStatement('DELETE FROM currency_market');
            $source = $this->createStub(MarketSource::class);
            /** @var array{currencies:array<string,int>,rates:array<string,array{value:string,date:string}>}|null $data */
            $data = ['currencies' => ['EUR' => 2, 'RSD' => 2], 'rates' => ['RSD' => ['value' => '125', 'date' => '2026-11-20']]];
            $source->method('fetch')->willReturnCallback(static function () use (&$data) {
                return $data ?? throw new MarketUnavailable();
            });
            $market = new ExchangeMarket($source, new DoctrineMarketStore($db), new \App\Currency\Adapter\ExactCurrencyArithmetic());
            $market->refresh();
            $market->refresh();
            $data['rates'] = [];
            $market->refresh();
            self::assertSame('125', $market->quote('EUR', 'RSD'));
            $data = null;
            $db->executeStatement("UPDATE currency_market SET refreshed_at = '2000-01-01'");
            self::assertSame('1/125', $market->quote('RSD', 'EUR'));
            $db->executeStatement('DELETE FROM currency_market');
            $this->expectException(MarketUnavailable::class);
            $market->quote('RSD', 'EUR');
        } finally {
            $db->rollBack();
        }
    }
}
