<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Credentials;

/**
 * @phpstan-type MergeAccount array{email: ?string, telegramId: ?int, active: bool}
 * @phpstan-type MergeTransfer array{id: string, createdAt: string, amount: int}
 * @phpstan-type MergeExpense array{id: string, createdAt: string, amount: int, title: string}
 */
final readonly class AccountMergeSnapshot
{
    /**
     * @param MergeAccount        $primary
     * @param MergeAccount        $joined
     * @param list<MergeTransfer> $transfers
     * @param list<MergeExpense>  $expenses
     */
    public function __construct(
        public array $primary,
        public array $joined,
        public array $transfers,
        public array $expenses,
        public string $financeVersion,
    ) {
    }
}
