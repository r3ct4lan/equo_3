<?php

declare(strict_types=1);

namespace App\Connects\Adapter\Persistence\Doctrine;

use App\Connects\Application\Port\ConnectRepositoryPort;
use App\Connects\Domain\CanonicalPair;
use App\Connects\Domain\Connect;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DoctrineConnectRepository implements ConnectRepositoryPort
{
    public function __construct(private Connection $db)
    {
    }

    public function exists(CanonicalPair $pair): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM connect WHERE first_user_id=? AND second_user_id=?', [$pair->first, $pair->second]);
    }

    public function findPairId(CanonicalPair $pair): ?string
    {
        $id = $this->db->fetchOne('SELECT id FROM connect WHERE first_user_id=? AND second_user_id=?', [$pair->first, $pair->second]);

        return false === $id ? null : (string) $id;
    }

    public function find(string $id): ?Connect
    {
        $row = $this->db->fetchAssociative('SELECT * FROM connect WHERE id = ?', [$id]);

        return false === $row ? null : $this->map($row);
    }

    public function getOrCreate(Connect $candidate): array
    {
        $created = 1 === $this->db->executeStatement('INSERT INTO connect (id, first_user_id, second_user_id, created_at) VALUES (?, ?, ?, ?) ON CONFLICT (first_user_id, second_user_id) DO NOTHING', [$candidate->id, $candidate->pair->first, $candidate->pair->second, $candidate->createdAt->format('Y-m-d H:i:sP')]);
        $row = $this->db->fetchAssociative('SELECT * FROM connect WHERE first_user_id = ? AND second_user_id = ?', [$candidate->pair->first, $candidate->pair->second]);
        if (false === $row) {
            throw new RuntimeException('Connect was not found after creation.');
        }

        return ['connect' => $this->map($row), 'created' => $created];
    }

    public function page(string $userId, int $limit, ?DateTimeImmutable $before, ?string $beforeId): array
    {
        $sql = 'SELECT * FROM connect WHERE (first_user_id = :user OR second_user_id = :user)';
        $params = ['user' => $userId];
        if (null !== $before) {
            $sql .= ' AND (created_at, id) < (:before, :id)';
            $params['before'] = $before->format('Y-m-d H:i:sP');
            $params['id'] = $beforeId;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT '.(int) $limit;

        return array_map($this->map(...), $this->db->fetchAllAssociative($sql, $params));
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): Connect
    {
        return new Connect((string) $row['id'], new CanonicalPair((string) $row['first_user_id'], (string) $row['second_user_id']), new DateTimeImmutable((string) $row['created_at']));
    }
}
