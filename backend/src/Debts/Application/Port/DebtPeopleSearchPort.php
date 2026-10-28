<?php

declare(strict_types=1);

namespace App\Debts\Application\Port;

interface DebtPeopleSearchPort
{
    /** @return list<array{id:string,name:string,isActive:bool,email:?string,sortName:string}> */
    public function search(string $viewerId, string $query, ?string $debtId, ?string $afterName, ?string $afterId): array;
}
