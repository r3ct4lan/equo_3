<?php

declare(strict_types=1);

namespace App\Infrastructure\Idempotency\Persistence\Doctrine\Record;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'idempotency_record')]
#[ORM\UniqueConstraint(
    name: 'uniq_idempotency_scope_operation_key',
    columns: ['scope', 'operation', 'idempotency_key'],
)]
#[ORM\Index(name: 'idx_idempotency_record_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_idempotency_record_expires_at', columns: ['expires_at'])]
class IdempotencyRecord
{
    /** @param array<string, mixed>|null $responseBody */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\Column(type: Types::TEXT)]
        private string $scope,
        #[ORM\Column(name: 'user_id', type: Types::GUID, nullable: true)]
        private ?string $userId,
        #[ORM\Column(type: Types::TEXT)]
        private string $operation,
        #[ORM\Column(name: 'idempotency_key', type: Types::TEXT)]
        private string $idempotencyKey,
        #[ORM\Column(name: 'request_hash', type: Types::TEXT)]
        private string $requestHash,
        #[ORM\Column(name: 'response_status', type: Types::INTEGER, nullable: true)]
        private ?int $responseStatus,
        #[ORM\Column(name: 'response_body', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
        private ?array $responseBody,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $expiresAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function requestHash(): string
    {
        return $this->requestHash;
    }

    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }

    /** @return array<string, mixed>|null */
    public function responseBody(): ?array
    {
        return $this->responseBody;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function restart(string $requestHash, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt): void
    {
        $this->userId = null;
        $this->requestHash = $requestHash;
        $this->responseStatus = null;
        $this->responseBody = null;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    /** @param array<string, mixed> $responseBody */
    public function complete(int $responseStatus, array $responseBody, ?string $userId): void
    {
        $this->responseStatus = $responseStatus;
        $this->responseBody = $responseBody;
        $this->userId = $userId;
    }
}
