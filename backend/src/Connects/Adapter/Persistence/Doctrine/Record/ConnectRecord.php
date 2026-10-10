<?php

declare(strict_types=1);

namespace App\Connects\Adapter\Persistence\Doctrine\Record;

use App\Connects\Domain\CanonicalPair;
use App\Connects\Domain\Connect;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'connect')]
#[ORM\UniqueConstraint(name: 'uniq_connect_pair', columns: ['first_user_id', 'second_user_id'])]
#[ORM\Index(name: 'idx_connect_first', columns: ['first_user_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_connect_second', columns: ['second_user_id', 'created_at', 'id'])]
class ConnectRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::GUID)] private string $id,
        #[ORM\Column(name: 'first_user_id', type: Types::GUID)] private string $firstUserId,
        #[ORM\Column(name: 'second_user_id', type: Types::GUID)] private string $secondUserId,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $createdAt,
    ) {
    }

    public function toDomain(): Connect
    {
        return new Connect($this->id, new CanonicalPair($this->firstUserId, $this->secondUserId), $this->createdAt);
    }
}
