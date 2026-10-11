<?php

declare(strict_types=1);

namespace App\Debts\Application\Api;

interface DebtAccess
{
    /** @return array{id:string,isAccessible:bool,title?:string,isDeleted?:bool} */
    public function reference(string $debtId, string $viewerId): array;
}
