<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Persistence\Doctrine;

use App\Debts\Application\Port\ReceiptStorage;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineReceiptStorage implements ReceiptStorage
{
    public function __construct(private Connection $db)
    {
    }

    public function prepare(string $owner, string $key, array $images): string
    {
        if ([] === $images || count($images) > 3) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
        $photos = json_encode(array_combine(range(1, count($images)), $images), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $fingerprint = hash('sha256', $photos);
        $id = Uuid::v4()->toRfc4122();

        return $this->db->transactional(function () use ($owner, $key, $photos, $fingerprint, $id): string {
            $this->db->executeStatement('INSERT INTO receipt_upload(id, owner_id, request_key, created_at, photos, fingerprint) VALUES(?,?,?,now(),?,?) ON CONFLICT(owner_id,request_key) DO NOTHING', [$id, $owner, $key, $photos, $fingerprint]);
            $row = $this->db->fetchAssociative('SELECT id,fingerprint FROM receipt_upload WHERE owner_id=? AND request_key=?', [$owner, $key]);
            if (!$row || $fingerprint !== $row['fingerprint']) {
                throw new ApplicationFailure(ApplicationFailureCode::IdempotencyKeyReused);
            }

            return $row['id'];
        });
    }

    public function draftPhotos(string $id, string $owner): array
    {
        $photos = $this->db->fetchOne('SELECT photos FROM receipt_upload WHERE id=? AND owner_id=? AND debt_id IS NULL', [$id, $owner]);
        if (false === $photos) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }

        return array_values(json_decode($photos, true, flags: JSON_THROW_ON_ERROR));
    }

    public function attach(string $id, string $owner, string $debt): void
    {
        if (1 !== $this->db->executeStatement('UPDATE receipt_upload SET debt_id=? WHERE id=? AND owner_id=? AND debt_id IS NULL', [$debt, $id, $owner])) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }
    }

    public function photoNumbers(string $debt): array
    {
        return array_map(intval(...), $this->db->fetchFirstColumn('SELECT jsonb_object_keys(photos) AS number FROM receipt_upload WHERE debt_id=? ORDER BY number', [$debt]));
    }
}
