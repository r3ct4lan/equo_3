<?php

declare(strict_types=1);

namespace App\Debts\Application\Port;

interface ReceiptReader
{
    /**
     * @param list<string> $images Validated image data URLs in upload order
     *
     * @return array{lines:list<array{name:?string,quantity:?string,unitPrice:?string,amount:?string,photo:int,context:?string}>,currency:?string,total:?string,discount:?string}|null
     */
    public function read(array $images): ?array;
}
