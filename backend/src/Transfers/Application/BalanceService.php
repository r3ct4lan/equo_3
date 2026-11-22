<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Currency\Application\Api\ExchangeMarket;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\Transfers\Application\Api\BalanceQuery;
use App\Transfers\Application\Port\TransferRepositoryPort;
use RuntimeException;

final readonly class BalanceService implements BalanceQuery
{
    public function __construct(private TransferRepositoryPort $repository, private AccountAccess $accounts, private ExchangeMarket $market)
    {
    }

    /** @return array{totalOwedAmount:int,totalReceivableAmount:int} */
    public function summary(string $viewerId): array
    {
        $owed = 0;
        $receivable = 0;
        foreach ($this->balances($viewerId, null) as $balance) {
            $owed += max(0, -$balance);
            $receivable += max(0, $balance);
        }
        if (max($owed, $receivable) > 9007199254740991) {
            throw new RuntimeException('Ledger summary exceeds the exact integer range.');
        }

        return ['totalOwedAmount' => $owed, 'totalReceivableAmount' => $receivable];
    }

    public function balance(string $connectId, string $viewerId): int
    {
        return $this->balances($viewerId, $connectId)[$connectId] ?? 0;
    }

    /** @return array<string,int> */
    private function balances(string $viewerId, ?string $connectId): array
    {
        $currency = $this->accounts->find($viewerId)?->currency ?? 'EUR';
        $balances = [];
        $rates = [];
        foreach ($this->repository->openAmounts($viewerId, $connectId) as $entry) {
            $amount = $entry['amount'];
            $from = $entry['currency'];
            if (0 !== $amount && $currency !== $from) {
                $quote = $entry['rateCurrency'] === $currency ? $entry['manualRate'] : null;
                $quote ??= $rates[$from] ??= $this->market->quote($from, $currency);
                $amount = $this->market->equivalent($amount, $from, $currency, $quote, $currency);
            }
            $id = $entry['connectId'];
            $value = ($balances[$id] ?? 0) + ($entry['senderId'] === $viewerId ? $amount : -$amount);
            if (abs($value) > 9007199254740991) {
                throw new RuntimeException('Ledger balance exceeds the exact integer range.');
            }
            $balances[$id] = $value;
        }

        return $balances;
    }
}

