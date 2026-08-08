<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\IssuedRefreshToken;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;

final readonly class RefreshTokenCodec implements RefreshTokenCodecPort
{
    private const string TOKEN_PATTERN = '/\Art\.[A-Za-z0-9_-]{43}\z/';

    public function issue(): IssuedRefreshToken
    {
        $publicToken = 'rt.'.$this->base64Url(random_bytes(32));

        return new IssuedRefreshToken($publicToken, $this->hash($publicToken));
    }

    public function digest(string $publicToken): ?string
    {
        if (1 !== preg_match(self::TOKEN_PATTERN, $publicToken)) {
            return null;
        }

        return $this->hash($publicToken);
    }

    private function hash(string $publicToken): string
    {
        return 'sha256:'.hash('sha256', $publicToken);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
