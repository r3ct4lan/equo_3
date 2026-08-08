<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Login;

use DateTimeImmutable;

final readonly class LoginResult
{
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public LoginUserView $user,
        private string $refreshToken,
        private string $csrfToken,
        private DateTimeImmutable $sessionExpiresAt,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(string, string, DateTimeImmutable): T $consumer
     *
     * @return T
     */
    public function withCookieSecrets(callable $consumer): mixed
    {
        return $consumer($this->refreshToken, $this->csrfToken, $this->sessionExpiresAt);
    }

    /** @return array{accessToken: string, expiresIn: int, user: array{id: string, name: string, email: string, isActive: bool}} */
    public function toResponseBody(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'expiresIn' => $this->expiresIn,
            'user' => $this->user->toArray(),
        ];
    }
}
