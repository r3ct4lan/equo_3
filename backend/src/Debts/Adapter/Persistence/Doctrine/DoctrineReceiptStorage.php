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

    public function attach(string $id, string $owner, string $debt, array $allocation = []): void
    {
        if (1 !== $this->db->executeStatement('UPDATE receipt_upload SET debt_id=?, allocation=?, draft=NULL WHERE id=? AND owner_id=? AND debt_id IS NULL', [$debt, json_encode($allocation, JSON_THROW_ON_ERROR), $id, $owner])) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }
    }

    public function saved(string $debt): ?array
    {
        $value = $this->db->fetchOne('SELECT allocation FROM receipt_upload WHERE debt_id=?', [$debt]);

        return is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function revise(string $debt, array $allocation): void
    {
        $this->db->update('receipt_upload', ['allocation' => json_encode($allocation, JSON_THROW_ON_ERROR)], ['debt_id' => $debt]);
    }

    public function photo(string $debt, int $number): ?string
    {
        $photo = $this->db->fetchOne('SELECT photos ->> CAST(? AS text) FROM receipt_upload WHERE debt_id=?', [(string) $number, $debt]);

        return is_string($photo) ? $photo : null;
    }

    public function removePhoto(string $debt, int $number): void
    {
        if (1 !== $this->db->executeStatement('UPDATE receipt_upload SET photos=photos - CAST(? AS text) WHERE debt_id=? AND jsonb_exists(photos,?)', [(string) $number, $debt, (string) $number])) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }
        $this->db->executeStatement('UPDATE debt SET version=version+1 WHERE id=?', [$debt]);
    }

    public function photoNumbers(string $debt): array
    {
        return array_map(intval(...), $this->db->fetchFirstColumn('SELECT jsonb_object_keys(photos) AS number FROM receipt_upload WHERE debt_id=? ORDER BY number', [$debt]));
    }

    public function status(string $id, string $owner): array
    {
        $row = $this->db->fetchAssociative("SELECT state, draft, COALESCE(started_at, created_at) < now() - interval '2 minutes' AS expired, (SELECT json_agg(k ORDER BY k) FROM jsonb_object_keys(photos) k) AS numbers FROM receipt_upload WHERE id=? AND owner_id=? AND debt_id IS NULL", [$id, $owner]);
        if (!$row) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }
        $state = $row['state'];
        if ($row['expired'] && in_array($state, ['pending', 'reading'], true)) {
            $state = 'failed';
        }

        return ['id' => $id, 'state' => $state, 'draft' => null === $row['draft'] ? null : json_decode($row['draft'], true, flags: JSON_THROW_ON_ERROR), 'photos' => array_values(array_map(intval(...), json_decode($row['numbers'], true, flags: JSON_THROW_ON_ERROR)))];
    }

    public function retry(string $id, string $owner, string $attempt): void
    {
        $this->db->executeStatement("UPDATE receipt_upload SET state='pending', attempt=?, started_at=now() WHERE id=? AND owner_id=? AND debt_id IS NULL AND COALESCE(attempt,request_key)<>? AND (state='failed' OR (state IN ('pending','reading') AND COALESCE(started_at,created_at)<now()-interval '2 minutes'))", [$attempt, $id, $owner, $attempt]);
    }

    public function claim(string $id, string $owner, string $attempt): ?array
    {
        $photos = $this->db->fetchOne("UPDATE receipt_upload SET state='reading',started_at=now() WHERE id=? AND owner_id=? AND COALESCE(attempt, request_key)=? AND debt_id IS NULL AND state='pending' RETURNING photos", [$id, $owner, $attempt]);

        return false === $photos ? null : array_values(json_decode($photos, true, flags: JSON_THROW_ON_ERROR));
    }

    public function finish(string $id, string $attempt, ?array $draft): void
    {
        $state = null === $draft || [] === ($draft['lines'] ?? []) ? 'failed' : 'ready';
        $this->db->executeStatement("UPDATE receipt_upload SET state=?, draft=? WHERE id=? AND COALESCE(attempt,request_key)=? AND state='reading' AND debt_id IS NULL", [$state, null === $draft ? null : json_encode($draft, JSON_THROW_ON_ERROR), $id, $attempt]);
    }
}
