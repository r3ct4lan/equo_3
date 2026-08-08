<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine\Record;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity]
#[ORM\Table(name: 'user_session')]
#[ORM\UniqueConstraint(name: 'uniq_user_session_refresh_token_hash', columns: ['refresh_token_hash'])]
#[ORM\Index(name: 'idx_user_session_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_user_session_expires_at', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_user_session_revoked_at', columns: ['revoked_at'])]
class UserSessionRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\ManyToOne(targetEntity: UserRecord::class)]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
        private UserRecord $user,
        #[ORM\Column(name: 'refresh_token_hash', type: Types::STRING, length: 255)]
        private string $refreshTokenHash,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'revoked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $revokedAt = null,
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
    public function refreshTokenHash(): string
    {
        return $this->refreshTokenHash;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function replaceRefreshTokenHash(string $refreshTokenHash): void
    {
        $this->refreshTokenHash = $refreshTokenHash;
    }

    public function revoke(?DateTimeImmutable $revokedAt): void
    {
        $this->revokedAt = $revokedAt;
    }
}
