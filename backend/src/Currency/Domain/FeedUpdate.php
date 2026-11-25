<?php

declare(strict_types=1);

namespace App\Currency\Domain;

final readonly class FeedUpdate
{
    /** Null data denotes successful validation of the stored representation.
     * @param array<string,int|array{value:string,date:string}>|null $data
     */
    public function __construct(public ?array $data, public FeedPolicy $policy, public bool $storeable = true)
    {
    }
}
