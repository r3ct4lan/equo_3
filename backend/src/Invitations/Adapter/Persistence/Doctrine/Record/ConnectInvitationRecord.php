<?php

declare(strict_types=1);

namespace App\Invitations\Adapter\Persistence\Doctrine\Record;

use App\Invitations\Domain\ConnectInvitation;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity]
#[ORM\Table(name: 'connect_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_connect_invitation_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_connect_invitation_author', columns: ['created_by_id', 'expires_at'])]
#[ORM\Index(name: 'idx_connect_invitation_accepted_by', columns: ['accepted_by_id'])]
#[ORM\Index(name: 'idx_connect_invitation_expires_at', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_connect_invitation_used_at', columns: ['used_at'])]
class ConnectInvitationRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::GUID)] private string $id,
        #[ORM\Column(name: 'created_by_id', type: Types::GUID)] private string $createdById,
        #[ORM\Column(name: 'token_hash', type: Types::TEXT)] private string $tokenHash,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'used_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)] private ?DateTimeImmutable $usedAt = null,
        #[ORM\Column(name: 'accepted_by_id', type: Types::GUID, nullable: true)] private ?string $acceptedById = null,
    ) {
    }

    #[Ignore]
    public function toDomain(): ConnectInvitation
    {
        return new ConnectInvitation($this->id, $this->createdById, $this->tokenHash, $this->createdAt, $this->expiresAt, $this->usedAt, $this->acceptedById);
    }

    public static function fromDomain(ConnectInvitation $i): self
    {
        return new self($i->id, $i->createdById, $i->tokenHash, $i->createdAt, $i->expiresAt, $i->usedAt, $i->acceptedById);
    }

    public function update(ConnectInvitation $i): void
    {
        $this->usedAt = $i->usedAt;
        $this->acceptedById = $i->acceptedById;
    }
}
