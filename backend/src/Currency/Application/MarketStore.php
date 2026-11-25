<?php

declare(strict_types=1);

namespace App\Currency\Application;

interface MarketStore
{
    /** @return array<string,int> */
    public function currencies(): array;

    /** @return array<string,array{value:string,date:string}> */
    public function rates(): array;
}
