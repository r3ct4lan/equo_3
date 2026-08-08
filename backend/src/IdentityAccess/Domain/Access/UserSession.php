<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\Access;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final class UserSession
{
    private const REFRESH_TOKEN_HASH_PATTERN = '/\Asha256:[a-f0-9]{64}\z/';

    private function __construct(
        public readonly string $id,
        public readonly string $userId,
        private string $refreshTokenHash,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $revokedAt,
    ) {
        self::assertRefreshTokenHash($refreshTokenHash);

        if ($expiresAt <= $createdAt) {
            throw new DomainException('A user session must expire after it is created.');
        }
    }

    public static function create(
        string $id,
        string $userId,
        string $refreshTokenHash,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id,
            $userId,
            $refreshTokenHash,
            $createdAt,
            $createdAt->modify('+30 days'),
            null,
        );
    }

    public static function rehydrate(
        string $id,
        string $userId,
        string $refreshTokenHash,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $revokedAt,
    ): self {
        return new self($id, $userId, $refreshTokenHash, $createdAt, $expiresAt, $revokedAt);
    }

    public function refreshTokenHash(): string
    {
        return $this->refreshTokenHash;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isActive(DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && $this->expiresAt > $now;
    }

    public function rotate(string $newRefreshTokenHash, DateTimeImmutable $now): void
    {
        self::assertRefreshTokenHash($newRefreshTokenHash);

        if (!$this->isActive($now)) {
            throw new DomainException('Only an active user session can rotate its refresh token.');
        }

        $this->refreshTokenHash = $newRefreshTokenHash;
    }

    public function revoke(DateTimeImmutable $revokedAt): void
    {
        if (null !== $this->revokedAt) {
            return;
        }

        $this->revokedAt = $revokedAt;
    }

    private static function assertRefreshTokenHash(string $refreshTokenHash): void
    {
        if (1 !== preg_match(self::REFRESH_TOKEN_HASH_PATTERN, $refreshTokenHash)) {
            throw new InvalidArgumentException('A user session refresh token hash must use the accepted storage format.');
        }
    }
}
