<?php

declare(strict_types=1);

namespace App\Currency\Adapter;

use App\Currency\Application\Api\ExchangeMarket;
use App\IdentityAccess\Application\Api\AccountAccess;
use Doctrine\DBAL\Connection;

final readonly class CurrencyOptions
{
    public function __construct(private Connection $db, private ExchangeMarket $market, private AccountAccess $accounts)
    {
    }

    /** @return list<string> */
    public function forUser(string $userId): array
    {
        $catalog = $this->market->currencies();
        $profile = $this->accounts->find($userId)->currency ?? 'EUR';
        $recent = $this->db->fetchFirstColumn(<<<'SQL'
            SELECT currency FROM (
                SELECT currency, created_at FROM debt WHERE created_by_id = ? AND NOT is_deleted
                UNION ALL
                SELECT currency, created_at FROM transfer WHERE created_by_id = ? AND type = 'MANUAL' AND NOT is_deleted
            ) AS operations
            GROUP BY currency
            ORDER BY MAX(created_at) DESC, currency
            SQL, [$userId, $userId]);

        $preferred = [$profile];
        foreach ($recent as $code) {
            if ($code !== $profile && isset($catalog[$code])) {
                $preferred[] = $code;
                if (5 === count($preferred)) {
                    break;
                }
            }
        }

        $others = array_diff(array_keys($catalog), $preferred);
        sort($others, SORT_STRING);

        return [...$preferred, ...$others];
    }
}
