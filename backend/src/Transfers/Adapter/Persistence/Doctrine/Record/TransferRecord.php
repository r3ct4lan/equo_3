<?php

declare(strict_types=1);

namespace App\Transfers\Adapter\Persistence\Doctrine\Record;

use App\Transfers\Domain\Transfer;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'transfer')]
#[ORM\UniqueConstraint(name: 'uniq_transfer_debt_share', columns: ['debt_participant_id'], options: ['where' => "(type = 'DEBT_SHARE'::text)"])]
#[ORM\Index(name: 'idx_transfer_history', columns: ['connect_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_transfer_incoming_history', columns: ['receiver_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_transfer_outgoing_history', columns: ['sender_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_transfer_balance', columns: ['connect_id', 'is_deleted'])]
#[ORM\Index(name: 'idx_transfer_sender', columns: ['sender_id'])]
#[ORM\Index(name: 'idx_transfer_receiver', columns: ['receiver_id'])]
#[ORM\Index(name: 'idx_transfer_author', columns: ['created_by_id'])]
#[ORM\Index(name: 'idx_transfer_debt', columns: ['debt_id'])]
#[ORM\Index(name: 'idx_transfer_participant', columns: ['debt_participant_id'])]
#[ORM\Index(name: 'idx_transfer_related', columns: ['related_transfer_id'])]
class TransferRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(name: 'id', type: Types::GUID)] private string $id,
        #[ORM\Column(name: 'connect_id', type: Types::GUID)] private string $connectId,
        #[ORM\Column(name: 'sender_id', type: Types::GUID)] private string $senderId,
        #[ORM\Column(name: 'receiver_id', type: Types::GUID)] private string $receiverId,
        #[ORM\Column(name: 'amount', type: Types::INTEGER)] private int $amount,
        #[ORM\Column(name: 'type', type: Types::TEXT)] private string $type,
        #[ORM\Column(name: 'created_by_id', type: Types::GUID, nullable: true)] private ?string $createdById,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'debt_id', type: Types::GUID, nullable: true)] private ?string $debtId,
        #[ORM\Column(name: 'debt_participant_id', type: Types::GUID, nullable: true)] private ?string $debtParticipantId,
        #[ORM\Column(name: 'related_transfer_id', type: Types::GUID, nullable: true)] private ?string $relatedTransferId,
        #[ORM\Column(name: 'is_deleted', type: Types::BOOLEAN, options: ['default' => false])] private bool $isDeleted = false,
        #[ORM\Column(name: 'version', type: Types::INTEGER, options: ['default' => 1])] private int $version = 1,
    ) {
    }

    public function toDomain(): Transfer
    {
        return new Transfer($this->id, $this->connectId, $this->senderId, $this->receiverId, $this->amount, $this->type, $this->createdById, $this->createdAt, $this->debtId, $this->debtParticipantId, $this->relatedTransferId, $this->isDeleted, $this->version);
    }
}
