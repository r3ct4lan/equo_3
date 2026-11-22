<?php

declare(strict_types=1);

namespace App\Currency\Application;

interface MarketSource
{
    /** @return array{currencies:array<string,int>,rates:array<string,array{value:string,date:string}>} */
    public function fetch(): array;
}
