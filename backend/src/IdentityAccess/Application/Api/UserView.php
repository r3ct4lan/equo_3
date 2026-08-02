<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Api;

use DateTimeImmutable;
use DateTimeZone;

final readonly class UserView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @return array{id: string, name: string, email: string, isActive: bool, createdAt: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'isActive' => $this->isActive,
            'createdAt' => $this->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
