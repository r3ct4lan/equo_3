<?php

declare(strict_types=1);

namespace App\Transfers\Adapter\Persistence\Doctrine;

use App\Transfers\Application\Port\TransferRepositoryPort;
use App\Transfers\Domain\Transfer;
use Doctrine\DBAL\Connection;

final readonly class DoctrineTransferRepository implements TransferRepositoryPort
{
    public function __construct(private Connection $db)
    {
    }

    public function save(Transfer $t): void
    {
        $this->db->insert('transfer', ['id' => $t->id, 'connect_id' => $t->connectId, 'sender_id' => $t->senderId, 'receiver_id' => $t->receiverId, 'amount' => $t->amount, 'type' => $t->type, 'created_by_id' => $t->createdById, 'created_at' => $t->createdAt->format('Y-m-d H:i:sP'), 'debt_id' => $t->debtId, 'debt_participant_id' => $t->debtParticipantId, 'related_transfer_id' => $t->relatedTransferId, 'is_deleted' => $t->isDeleted, 'version' => $t->version], ['is_deleted' => 'boolean']);
    }

    public function sharesForDebt(string $debtId): array
    {
        $result = [];
        foreach ($this->db->fetchAllAssociative("SELECT debt_participant_id, id FROM transfer WHERE debt_id=? AND type='DEBT_SHARE'", [$debtId]) as $row) {
            $result[(string) $row['debt_participant_id']] = (string) $row['id'];
        }

        return $result;
    }
}
