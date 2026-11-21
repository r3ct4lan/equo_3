<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Credentials;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\AccountMergeSnapshotPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CredentialRepositoryPort;
use App\IdentityAccess\Application\Port\StoredEmailChangeToken;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;

final readonly class ReviewAccountMerge
{
    public function __construct(
        private CredentialRepositoryPort $credentials,
        private AccountMergeSnapshotPort $accounts,
        private ClockPort $clock,
        private TransactionPort $transaction,
    ) {
    }

    public function preview(string $userId, string $sessionId): AccountMergePreview
    {
        return $this->transaction->run(function () use ($userId, $sessionId): AccountMergePreview {
            $this->requireSession($userId, $sessionId);

            return $this->fromProof($userId, $this->requireProof($userId, $sessionId));
        });
    }

    public function requireSession(string $userId, string $sessionId): void
    {
        $user = $this->credentials->accountForUpdate($userId);
        if (null === $user || !$user->active || !$this->credentials->activeSession($userId, $sessionId, $this->clock->now())) {
            throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
        }
    }

    public function requireProof(string $userId, string $sessionId): StoredEmailChangeToken
    {
        return $this->credentials->currentAction($userId, $sessionId, UserActionTokenPurpose::MergeAccount)
            ?? throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
    }

    public function targetId(string $userId, StoredEmailChangeToken $proof): string
    {
        $target = $proof->payload['targetId'] ?? null;
        if (!is_string($target) || $target === $userId) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
        }

        return $target;
    }

    public function fromProof(string $userId, StoredEmailChangeToken $proof): AccountMergePreview
    {
        $target = $this->targetId($userId, $proof);
        $mode = $proof->payload['mode'] ?? null;
        if (!is_string($mode)) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
        }
        $snapshot = $this->accounts->snapshot($userId, $target);
        if (null === $snapshot || !$snapshot->joined['active']) {
            throw new ApplicationFailure(ApplicationFailureCode::CredentialConflict);
        }

        $from = $snapshot->primary;
        $to = $snapshot->joined;
        if ('link_email' === $mode && null === $from['email'] && $to['email'] === ($proof->payload['email'] ?? null)) {
            $email = $to['email'];
            $telegram = $from['telegramId'];
        } elseif (('link_telegram' === $mode || 'replace_telegram' === $mode)
            && $from['telegramId'] === ($proof->payload['originalTelegramId'] ?? null)
            && $to['telegramId'] === ($proof->payload['telegramId'] ?? null)
        ) {
            $email = $from['email'];
            $telegram = $to['telegramId'];
        } else {
            throw new ApplicationFailure(ApplicationFailureCode::CredentialConflict);
        }
        if (null === $email || null === $telegram) {
            throw new ApplicationFailure(ApplicationFailureCode::CredentialConflict);
        }

        $version = hash('sha256', json_encode([$proof->id, $from, $to, $snapshot->financeVersion], JSON_THROW_ON_ERROR));

        return new AccountMergePreview($snapshot, $proof, $target, $mode, $version, $email, $telegram);
    }
}
