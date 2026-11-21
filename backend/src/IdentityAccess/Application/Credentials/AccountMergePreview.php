<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Credentials;

use App\IdentityAccess\Application\Port\StoredEmailChangeToken;

/**
 * @phpstan-import-type MergeTransfer from AccountMergeSnapshot
 * @phpstan-import-type MergeExpense from AccountMergeSnapshot
 */
final readonly class AccountMergePreview
{
    public function __construct(
        public AccountMergeSnapshot $snapshot,
        public StoredEmailChangeToken $proof,
        public string $targetId,
        public string $mode,
        public string $version,
        public string $email,
        public int $telegramId,
    ) {
    }

    /**
     * @return array{
     *     version: string, mode: string, email: string, discardedEmail: ?string,
     *     replacesTelegram: bool, discardsTelegram: bool,
     *     disappearingTransfers: list<MergeTransfer>, disappearingExpenses: list<MergeExpense>
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'mode' => $this->mode,
            'email' => $this->email,
            'discardedEmail' => $this->snapshot->joined['email'] === $this->email ? null : $this->snapshot->joined['email'],
            'replacesTelegram' => 'replace_telegram' === $this->mode,
            'discardsTelegram' => null !== $this->snapshot->joined['telegramId'] && $this->snapshot->joined['telegramId'] !== $this->telegramId,
            'disappearingTransfers' => $this->snapshot->transfers,
            'disappearingExpenses' => $this->snapshot->expenses,
        ];
    }
}
