<?php

declare(strict_types=1);

namespace App\Infrastructure\Idempotency\Persistence\Doctrine;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\IdempotencyPort;
use App\IdentityAccess\Application\Port\RequestFingerprintPort;
use App\IdentityAccess\Application\Port\StoredHttpResult;
use App\IdentityAccess\Application\Port\UuidPort;
use App\Infrastructure\Idempotency\Persistence\Doctrine\Record\IdempotencyRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final class DoctrineIdempotency implements IdempotencyPort
{
    /** @var array<string, IdempotencyRecord> */
    private array $active = [];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UuidPort $uuid,
        private RequestFingerprintPort $requestFingerprint,
    ) {
    }

    public function begin(
        string $scope,
        string $operation,
        string $key,
        string $canonicalRequest,
        DateTimeImmutable $now,
    ): ?StoredHttpResult {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))',
            ['lock_key' => $scope."\0".$operation."\0".$key],
        );

        $record = $this->entityManager->getRepository(IdempotencyRecord::class)->findOneBy([
            'scope' => $scope,
            'operation' => $operation,
            'idempotencyKey' => $key,
        ]);

        if (!$record instanceof IdempotencyRecord) {
            $requestHash = $this->requestFingerprint->create($canonicalRequest);
            $record = new IdempotencyRecord(
                $this->uuid->generate(),
                $scope,
                null,
                $operation,
                $key,
                $requestHash,
                null,
                null,
                $now,
                $now->modify('+24 hours'),
            );
            $this->entityManager->persist($record);
            $this->active[$this->index($scope, $operation, $key)] = $record;

            return null;
        }

        $this->active[$this->index($scope, $operation, $key)] = $record;

        if ($record->expiresAt() <= $now) {
            $record->restart(
                $this->requestFingerprint->create($canonicalRequest),
                $now,
                $now->modify('+24 hours'),
            );

            return null;
        }

        if (!$this->requestFingerprint->matches($record->requestHash(), $canonicalRequest)) {
            throw new ApplicationFailure(ApplicationFailureCode::IdempotencyKeyReused);
        }

        if (null === $record->responseStatus() || null === $record->responseBody()) {
            throw new RuntimeException('A locked idempotency record has no completed result.');
        }

        return new StoredHttpResult($record->responseStatus(), $record->responseBody());
    }

    public function complete(
        string $scope,
        string $operation,
        string $key,
        int $status,
        array $body,
        ?string $userId,
    ): void {
        $record = $this->active[$this->index($scope, $operation, $key)]
            ?? $this->entityManager->getRepository(IdempotencyRecord::class)->findOneBy([
                'scope' => $scope,
                'operation' => $operation,
                'idempotencyKey' => $key,
            ]);

        if (!$record instanceof IdempotencyRecord) {
            throw new RuntimeException('The idempotency reservation is missing.');
        }

        $record->complete($status, $body, $userId);
    }

    private function index(string $scope, string $operation, string $key): string
    {
        return $scope."\0".$operation."\0".$key;
    }
}
