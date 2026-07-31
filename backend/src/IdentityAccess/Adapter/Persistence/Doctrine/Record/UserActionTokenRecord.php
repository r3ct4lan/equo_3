<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine\Record;

use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity]
#[ORM\Table(name: 'user_action_token')]
#[ORM\UniqueConstraint(name: 'uniq_user_action_token_hash', columns: ['token_hash'])]
#[ORM\UniqueConstraint(
    name: 'uniq_user_action_token_unfinished',
    columns: ['user_id', 'purpose'],
    options: ['where' => '((used_at IS NULL) AND (invalidated_at IS NULL))'],
)]
#[ORM\Index(name: 'idx_user_action_token_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_user_action_token_purpose', columns: ['purpose'])]
#[ORM\Index(name: 'idx_user_action_token_expires_at', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_user_action_token_used_at', columns: ['used_at'])]
class UserActionTokenRecord
{
    /** @param array<string, mixed>|null $payload */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\ManyToOne(targetEntity: UserRecord::class)]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
        private UserRecord $user,
        #[ORM\Column(name: 'token_hash', type: Types::TEXT)]
        private string $tokenHash,
        #[ORM\Column(
            type: Types::TEXT,
            enumType: UserActionTokenPurpose::class,
        )]
        private UserActionTokenPurpose $purpose,
        #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
        private ?array $payload,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'used_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $usedAt = null,
        #[ORM\Column(name: 'invalidated_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $invalidatedAt = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function user(): UserRecord
    {
        return $this->user;
    }

    #[Ignore]
    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function purpose(): UserActionTokenPurpose
    {
        return $this->purpose;
    }

    /** @return array<string, mixed>|null */
    #[Ignore]
    public function payload(): ?array
    {
        return $this->payload;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function usedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function invalidatedAt(): ?DateTimeImmutable
    {
        return $this->invalidatedAt;
    }
}
