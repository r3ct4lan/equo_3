<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Persistence\Doctrine\Record;

use App\Debts\Domain\DebtParticipant;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'debt_participant')]
#[ORM\UniqueConstraint(name: 'uniq_debt_participant_user', columns: ['debt_id', 'user_id'])]
#[ORM\Index(name: 'idx_debt_participant_user', columns: ['user_id', 'is_deleted', 'debt_id'])]
#[ORM\Index(name: 'idx_debt_participant_current', columns: ['debt_id', 'is_deleted'])]
class DebtParticipantRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(name: 'id', type: Types::GUID)] private string $id,
        #[ORM\Column(name: 'debt_id', type: Types::GUID)] private string $debtId,
        #[ORM\Column(name: 'user_id', type: Types::GUID)] private string $userId,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'is_deleted', type: Types::BOOLEAN, options: ['default' => false])] private bool $isDeleted = false,
    ) {
    }

    public function toDomain(): DebtParticipant
    {
        return new DebtParticipant($this->id, $this->debtId, $this->userId, $this->createdAt, $this->isDeleted);
    }
}
