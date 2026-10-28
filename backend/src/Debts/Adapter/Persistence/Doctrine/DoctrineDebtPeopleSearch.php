<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Persistence\Doctrine;

use App\Debts\Application\Port\DebtPeopleSearchPort;
use Doctrine\DBAL\Connection;

final readonly class DoctrineDebtPeopleSearch implements DebtPeopleSearchPort
{
    public function __construct(private Connection $db)
    {
    }

    public function search(string $viewerId, string $query, ?string $debtId, ?string $afterName, ?string $afterId): array
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
        $sql = <<<'SQL'
            WITH candidates AS (
              SELECT u.id, u.name, u.is_active,
                EXISTS (SELECT 1 FROM connect c WHERE (c.first_user_id=:viewer AND c.second_user_id=u.id) OR (c.second_user_id=:viewer AND c.first_user_id=u.id)) AS connected,
                u.email
              FROM app_user u
              WHERE u.id=:viewer OR (u.is_active=true AND (
                EXISTS (SELECT 1 FROM connect c WHERE (c.first_user_id=:viewer AND c.second_user_id=u.id) OR (c.second_user_id=:viewer AND c.first_user_id=u.id)) OR
                EXISTS (SELECT 1 FROM debt d JOIN debt_participant p ON p.debt_id=d.id AND p.user_id=u.id AND p.is_deleted=false
                  WHERE d.is_deleted=false AND (d.created_by_id=:viewer OR d.payer_id=:viewer OR EXISTS
                    (SELECT 1 FROM debt_participant own WHERE own.debt_id=d.id AND own.user_id=:viewer AND own.is_deleted=false)))
              )) OR (CAST(:debt AS uuid) IS NOT NULL AND EXISTS (SELECT 1 FROM debt_participant current_part WHERE current_part.debt_id=CAST(:debt AS uuid) AND current_part.user_id=u.id AND current_part.is_deleted=false))
            )
            SELECT id, name, is_active, CASE WHEN connected THEN email ELSE NULL END AS visible_email, lower(name) AS sort_name
            FROM candidates
            WHERE (lower(name) LIKE lower(:pattern) ESCAPE E'\\' OR (connected AND lower(email) LIKE lower(:pattern) ESCAPE E'\\'))
            SQL;
        $params = ['viewer' => $viewerId, 'debt' => $debtId, 'pattern' => $pattern];
        if (null !== $afterName) {
            $sql .= ' AND (lower(name),id) > (:after_name, CAST(:after_id AS uuid))';
            $params['after_name'] = $afterName;
            $params['after_id'] = $afterId;
        }
        $sql .= ' ORDER BY lower(name), id LIMIT 6';

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'name' => (string) $row['name'], 'isActive' => (bool) $row['is_active'],
            'email' => null === $row['visible_email'] ? null : (string) $row['visible_email'],
            'sortName' => (string) $row['sort_name'],
        ], $this->db->fetchAllAssociative($sql, $params));
    }
}
