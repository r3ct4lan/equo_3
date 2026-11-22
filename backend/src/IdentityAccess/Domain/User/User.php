<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\User;

use DateTimeImmutable;
use DomainException;

final class User
{
    public const int MAX_TELEGRAM_ID = 4503599627370495;

    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?EmailAddress $email,
        public readonly ?string $passwordHash,
        private bool $active,
        public readonly DateTimeImmutable $createdAt,
        public readonly string $locale,
        public readonly ?int $telegramId,
    ) {
        if ('' === trim($name)) {
            throw new DomainException('A user name must not be blank.');
        }

        if (!in_array($locale, ['en', 'ru'], true)) {
            throw new DomainException('Unsupported locale.');
        }
    }

    public static function register(
        string $id,
        string $name,
        EmailAddress $email,
        string $passwordHash,
        DateTimeImmutable $createdAt,
        string $locale = 'en',
    ): self {
        return new self($id, $name, $email, $passwordHash, false, $createdAt, $locale, null);
    }

    public static function registerWithTelegram(
        string $id,
        string $name,
        int $telegramId,
        DateTimeImmutable $createdAt,
        string $locale = 'en',
    ): self {
        if ($telegramId < 1 || $telegramId > self::MAX_TELEGRAM_ID) {
            throw new DomainException('Invalid Telegram user ID.');
        }

        return new self($id, $name, null, null, true, $createdAt, $locale, $telegramId);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function activate(): void
    {
        if ($this->active) {
            throw new DomainException('Only an inactive account can be activated.');
        }

        $this->active = true;
    }
}
