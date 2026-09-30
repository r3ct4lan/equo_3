<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\IssuedRefreshToken;
use App\IdentityAccess\Application\Port\ParsedRefreshToken;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final readonly class RefreshTokenCodec implements RefreshTokenCodecPort
{
    private const string TOKEN_PATTERN = '/\Art2\.([0-9a-f-]{36})\.([A-Za-z0-9_-]{43})\z/D';

    public function issueForSession(string $sessionId): IssuedRefreshToken
    {
        if (!$this->isCanonicalUuid($sessionId)) {
            throw new InvalidArgumentException('The refresh token session locator is invalid.');
        }

        $publicToken = 'rt2.'.$sessionId.'.'.$this->base64Url(random_bytes(32));

        return new IssuedRefreshToken($publicToken, $this->hash($publicToken), $sessionId);
    }

    public function parse(string $publicToken): ?ParsedRefreshToken
    {
        if (1 !== preg_match(self::TOKEN_PATTERN, $publicToken, $matches)) {
            return null;
        }

        $sessionId = $matches[1];
        $randomSegment = $matches[2];

        if (!$this->isCanonicalUuid($sessionId) || !$this->isCanonicalRandomSegment($randomSegment)) {
            return null;
        }

        return new ParsedRefreshToken($sessionId, $this->hash($publicToken));
    }

    private function hash(string $publicToken): string
    {
        return 'sha256:'.hash('sha256', $publicToken);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function isCanonicalUuid(string $value): bool
    {
        return Uuid::isValid($value) && Uuid::fromString($value)->toRfc4122() === $value;
    }

    private function isCanonicalRandomSegment(string $value): bool
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').'=', true);

        return false !== $decoded && 32 === strlen($decoded) && $this->base64Url($decoded) === $value;
    }
}
