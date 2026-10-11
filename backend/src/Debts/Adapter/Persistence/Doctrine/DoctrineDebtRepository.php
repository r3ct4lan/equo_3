<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Persistence\Doctrine;

use App\Debts\Application\Port\DebtRepositoryPort;
use App\Debts\Domain\Debt;
use App\Debts\Domain\DebtParticipant;
use App\Debts\Domain\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineDebtRepository implements DebtRepositoryPort
{
    public function __construct(private Connection $db)
    {
    }

    public function save(Debt $debt): void
    {
        $this->db->insert('debt', ['id' => $debt->id, 'title' => $debt->title, 'description' => $debt->description, 'total_amount' => $debt->totalAmount->cents, 'payer_id' => $debt->payerId, 'created_by_id' => $debt->createdById, 'created_at' => $debt->createdAt->format('Y-m-d H:i:sP'), 'is_deleted' => $debt->isDeleted, 'version' => $debt->version], ['is_deleted' => 'boolean']);
        foreach ($debt->participants as $p) {
            $this->db->insert('debt_participant', ['id' => $p->id, 'debt_id' => $debt->id, 'user_id' => $p->userId, 'created_at' => $p->createdAt->format('Y-m-d H:i:sP'), 'is_deleted' => $p->isDeleted], ['is_deleted' => 'boolean']);
        }
    }

    public function find(string $id): ?Debt
    {
        $row = $this->db->fetchAssociative('SELECT * FROM debt WHERE id=?', [$id]);
        if (false === $row) {
            return null;
        }
        $parts = array_map(static fn (array $p): DebtParticipant => new DebtParticipant((string) $p['id'], (string) $p['debt_id'], (string) $p['user_id'], new DateTimeImmutable((string) $p['created_at']), (bool) $p['is_deleted']), $this->db->fetchAllAssociative('SELECT * FROM debt_participant WHERE debt_id=? ORDER BY created_at, id', [$id]));

        return new Debt((string) $row['id'], (string) $row['title'], null === $row['description'] ? null : (string) $row['description'], new Money((int) $row['total_amount']), (string) $row['payer_id'], (string) $row['created_by_id'], new DateTimeImmutable((string) $row['created_at']), $parts, (bool) $row['is_deleted'], (int) $row['version']);
    }

    public function isCoParticipant(string $authorId, string $candidateId): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM debt d JOIN debt_participant candidate ON candidate.debt_id=d.id AND candidate.user_id=:candidate AND candidate.is_deleted=false WHERE d.is_deleted=false AND (d.created_by_id=:author OR d.payer_id=:author OR EXISTS (SELECT 1 FROM debt_participant own WHERE own.debt_id=d.id AND own.user_id=:author AND own.is_deleted=false)) LIMIT 1', ['candidate' => $candidateId, 'author' => $authorId]);
    }
}
