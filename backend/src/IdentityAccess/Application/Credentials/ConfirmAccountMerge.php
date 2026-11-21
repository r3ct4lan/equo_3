<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Credentials;

use App\Debts\Application\Api\AccountExpenseMerge;
use App\IdentityAccess\Application\Port\AccountMergeExecutionPort;
use App\IdentityAccess\Application\Port\AccountMergeLedgerPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CredentialRepositoryPort;
use App\IdentityAccess\Application\Port\TransactionPort;

final readonly class ConfirmAccountMerge
{
    public function __construct(
        private ReviewAccountMerge $review,
        private AccountMergeExecutionPort $storage,
        private AccountExpenseMerge $expenses,
        private AccountMergeLedgerPort $ledger,
        private CredentialRepositoryPort $credentials,
        private ClockPort $clock,
        private TransactionPort $transaction,
    ) {
    }

    /** @return array{status: 'merged'}|array{status: 'preview_changed', preview: array<string, mixed>} */
    public function confirm(string $userId, string $sessionId, string $version): array
    {
        return $this->transaction->run(function () use ($userId, $sessionId, $version): array {
            $this->review->requireSession($userId, $sessionId);
            if ($this->storage->completed($userId, $sessionId, $version)) {
                return ['status' => 'merged'];
            }

            $proof = $this->review->requireProof($userId, $sessionId);
            $joined = $this->review->targetId($userId, $proof);
            $this->storage->lockFootprint($userId, $joined);
            $preview = $this->review->fromProof($userId, $proof);
            if (!hash_equals($preview->version, $version)) {
                return ['status' => 'preview_changed', 'preview' => $preview->toArray()];
            }

            $this->storage->lockTelegramIdentities($preview);
            $now = $this->clock->now();
            $this->expenses->apply($userId, $joined);
            $this->ledger->apply($userId, $joined, $now);
            $this->credentials->revokeOtherSessions($joined, null, $now);
            if ('replace_telegram' === $preview->mode) {
                $this->credentials->revokeOtherSessions($userId, $sessionId, $now);
            }
            $this->storage->complete($userId, $preview, $now);

            return ['status' => 'merged'];
        });
    }
}
