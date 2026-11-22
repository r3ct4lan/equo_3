<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

use App\IdentityAccess\Application\Credentials\AccountMergeSnapshot;

interface AccountMergeSnapshotPort
{
    public function snapshot(string $primary, string $joined): ?AccountMergeSnapshot;
}
