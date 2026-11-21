<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine\Record;

use App\IdentityAccess\Application\Port\StoredLoginIdentity;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_app_user_telegram_id', columns: ['telegram_id'])]
class UserRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\Column(type: Types::TEXT)]
        private string $name,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $email,
        #[ORM\Column(name: 'password_hash', type: Types::TEXT, nullable: true)]
        private ?string $passwordHash,
        #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
        private bool $isActive,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'pending_invitation_id', type: Types::GUID, nullable: true)]
        private ?string $pendingInvitationId = null,
        #[ORM\Column(type: Types::STRING, length: 2, options: ['default' => 'en'])]
        private string $locale = 'en',
        #[ORM\Column(name: 'telegram_id', type: Types::BIGINT, nullable: true)]
        private ?int $telegramId = null,
        #[ORM\Column(name: 'strict_session_access', type: Types::BOOLEAN, options: ['default' => false])]
        private bool $strictSessionAccess = false,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'merged_into_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
        private ?self $mergedInto = null,
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

    public function email(): ?string
    {
        return $this->email;
    }

    #[Ignore]
    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    #[Ignore]
    public function passwordIdentity(): ?StoredLoginIdentity
    {
        if (null === $this->email || null === $this->passwordHash) {
            return null;
        }

        return new StoredLoginIdentity($this->id, $this->name, $this->email, $this->passwordHash, $this->isActive, $this->locale);
    }

    #[Ignore]
    public function telegramId(): ?int
    {
        return $this->telegramId;
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
        if (null !== $this->mergedInto) {
            throw new LogicException('Absorbed accounts cannot be activated.');
        }
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

    public function changeTelegramId(int $telegramId): void
    {
        $this->telegramId = $telegramId;
    }

    public function requireActiveAccessSession(): void
    {
        $this->strictSessionAccess = true;
    }

    public function assignEmailCredentials(string $email, string $passwordHash): void
    {
        $this->email = $email;
        $this->passwordHash = $passwordHash;
    }

    public function changeEmail(string $email): void
    {
        $this->email = $email;
    }
}
