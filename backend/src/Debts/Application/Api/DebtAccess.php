<?php

declare(strict_types=1);

namespace App\Debts\Application\Api;

interface DebtAccess
{
    /** @return array{id:string,isAccessible:bool,title?:string,isDeleted?:bool} */
    public function reference(string $debtId, string $viewerId): array;

    public function allowsManualConnect(string $debtId, string $firstUserId, string $secondUserId): bool;
}
