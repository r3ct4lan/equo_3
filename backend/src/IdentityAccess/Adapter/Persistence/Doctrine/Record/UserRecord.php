<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine\Record;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
class UserRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\Column(type: Types::TEXT)]
        private string $name,
        #[ORM\Column(type: Types::TEXT)]
        private string $email,
        #[ORM\Column(name: 'password_hash', type: Types::TEXT)]
        private string $passwordHash,
        #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
        private bool $isActive,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'pending_invitation_id', type: Types::GUID, nullable: true)]
        private ?string $pendingInvitationId = null,
        #[ORM\Column(type: Types::STRING, length: 2, options: ['default' => 'en'])]
        private string $locale = 'en',
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function changeLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function email(): string
    {
        return $this->email;
    }

    #[Ignore]
    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function pendingInvitationId(): ?string
    {
        return $this->pendingInvitationId;
    }

    public function clearPendingInvitation(): void
    {
        $this->pendingInvitationId = null;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    public function replacePasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function changeEmail(string $email): void
    {
        $this->email = $email;
    }
}
