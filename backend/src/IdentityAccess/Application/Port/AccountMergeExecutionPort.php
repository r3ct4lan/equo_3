<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Application\Credentials\AccountMergePreview;
use DateTimeImmutable;

interface AccountMergeExecutionPort
{
    public function completed(string $userId, string $sessionId, string $version): bool;

    public function lockFootprint(string $primary, string $joined): void;

    public function lockTelegramIdentities(AccountMergePreview $preview): void;

    public function complete(string $primary, AccountMergePreview $preview, DateTimeImmutable $now): void;
}
