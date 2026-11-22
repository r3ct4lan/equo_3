<?php

declare(strict_types=1);

namespace App\Currency\Adapter;

use App\Currency\Application\MarketStore;
use Doctrine\DBAL\Connection;

final readonly class DoctrineMarketStore implements MarketStore
{
    public function __construct(private Connection $db)
    {
    }

    public function read(): array
    {
        $row = $this->db->fetchAssociative("SELECT currencies, rates, refreshed_at > CURRENT_TIMESTAMP - INTERVAL '1 day' AS fresh FROM currency_market WHERE id = 1");
        if (false === $row) {
            return ['currencies' => [], 'rates' => [], 'fresh' => false];
        }

        return ['currencies' => json_decode($row['currencies'], true, flags: JSON_THROW_ON_ERROR), 'rates' => json_decode($row['rates'], true, flags: JSON_THROW_ON_ERROR), 'fresh' => (bool) $row['fresh']];
    }

    public function save(array $snapshot): void
    {
        $this->db->transactional(function () use ($snapshot): void {
            $this->db->executeStatement("INSERT INTO currency_market (id, currencies, rates, refreshed_at) VALUES (1, '{}', '{}', CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING");
            $stored = $this->db->fetchOne('SELECT rates FROM currency_market WHERE id = 1 FOR UPDATE');
            $rates = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
            foreach ($snapshot['rates'] as $code => $quote) {
                if ($quote['date'] >= ($rates[$code]['date'] ?? '')) {
                    $rates[$code] = $quote;
                }
            }
            $this->db->executeStatement('UPDATE currency_market SET currencies = ?, rates = ?, refreshed_at = CURRENT_TIMESTAMP WHERE id = 1', [json_encode($snapshot['currencies'], JSON_THROW_ON_ERROR), json_encode($rates, JSON_THROW_ON_ERROR)]);
        });
    }
}
