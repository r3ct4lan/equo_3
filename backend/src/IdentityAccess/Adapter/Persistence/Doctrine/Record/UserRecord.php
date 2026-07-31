<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine\Record;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

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

    public function email(): string
    {
        return $this->email;
    }

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
}
