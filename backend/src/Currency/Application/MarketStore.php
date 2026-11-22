<?php

declare(strict_types=1);

namespace App\Currency\Application;

interface MarketStore
{
    /** @return array{currencies:array<string,int>,rates:array<string,array{value:string,date:string}>,fresh:bool} */
    public function read(): array;

    /** @param array{currencies:array<string,int>,rates:array<string,array{value:string,date:string}>} $snapshot */
    public function save(array $snapshot): void;
}
