<?php

declare(strict_types=1);

namespace App\Currency\Adapter\Persistence\Doctrine\Record;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'currency_market')]
class MarketRecord
{
    /** @param array<string,int> $currencies
     * @param array<string,array{value:string,date:string}> $rates
     * @param array<string,mixed>                           $cachePolicy
     */
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::SMALLINT)] private int $id,
        #[ORM\Column(type: Types::JSONB)] private array $currencies,
        #[ORM\Column(type: Types::JSONB)] private array $rates,
        #[ORM\Column(name: 'refreshed_at', type: Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $refreshedAt,
        #[ORM\Column(name: 'cache_policy', type: Types::JSONB, options: ['default' => '{}'])] private array $cachePolicy = [],
    ) {
    }
}
