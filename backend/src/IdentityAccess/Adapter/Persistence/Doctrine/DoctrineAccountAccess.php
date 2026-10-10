<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\AccountSummary;
use Doctrine\DBAL\Connection;

final readonly class DoctrineAccountAccess implements AccountAccess
{
    public function __construct(private Connection $db)
    {
    }

    public function find(string $id, bool $forUpdate = false): ?AccountSummary
    {
        $row = $this->db->fetchAssociative('SELECT id, name, is_active FROM app_user WHERE id = ?'.($forUpdate ? ' FOR UPDATE' : ''), [$id]);

        return false === $row ? null : new AccountSummary((string) $row['id'], (string) $row['name'], in_array($row['is_active'], [true, 1, '1', 't'], true));
    }
}
