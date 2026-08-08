<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Refresh;

use DateTimeImmutable;

final readonly class RefreshResult
{
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
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

    /** @return array{accessToken: string, expiresIn: int} */
    public function toResponseBody(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'expiresIn' => $this->expiresIn,
        ];
    }
}
