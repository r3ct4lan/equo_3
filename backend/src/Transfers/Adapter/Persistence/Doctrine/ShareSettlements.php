<?php

declare(strict_types=1);

namespace App\Transfers\Adapter\Persistence\Doctrine;

use App\Currency\Application\Api\CurrencyArithmetic;
use App\Transfers\Application\Api\ReconcileShare;
use Doctrine\DBAL\Connection;

final readonly class ShareSettlements implements ReconcileShare
{
    public function __construct(private Connection $db, private CurrencyArithmetic $arithmetic)
    {
    }

    /** Rebuild allocations in payment order while holding the share lock inside the writer transaction. */
    public function reconcile(string $shareId): void
    {
        $share = $this->db->fetchAssociative("SELECT amount, currency, is_deleted, hidden_by_merge FROM transfer WHERE id = ? AND type = 'DEBT_SHARE' FOR UPDATE", [$shareId]);
        if (false === $share) {
            return;
        }
        $active = !$share['is_deleted'] && !$share['hidden_by_merge'];
        $remaining = (int) $share['amount'];
        $payments = $this->db->fetchAllAssociative('SELECT id, amount, currency, settlement_rate, is_deleted, hidden_by_merge FROM transfer WHERE related_transfer_id = ? AND settlement_rate IS NOT NULL ORDER BY created_at, id FOR UPDATE', [$shareId]);
        foreach ($payments as $payment) {
            $credit = 0;
            $excess = null;
            if ($active && !$payment['is_deleted'] && !$payment['hidden_by_merge']) {
                $allocation = $this->arithmetic->settle((int) $payment['amount'], $remaining, $payment['currency'], $share['currency'], $payment['settlement_rate']);
                $credit = $allocation['credited'];
                $excess = $allocation['excess'];
                $remaining -= $credit;
            }
            $this->db->update('transfer', ['credited_amount' => $credit, 'excess_amount' => $excess], ['id' => $payment['id']]);
        }
        $this->db->update('transfer', ['credited_amount' => (int) $share['amount'] - $remaining], ['id' => $shareId]);
    }
}

