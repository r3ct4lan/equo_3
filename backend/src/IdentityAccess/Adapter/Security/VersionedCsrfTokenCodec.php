<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use InvalidArgumentException;
use JsonException;

final readonly class VersionedCsrfTokenCodec implements CsrfTokenCodecPort
{
    private const string VERSION_PATTERN = '/\A[A-Za-z0-9_-]{1,16}\z/';
    private const string SESSION_ID_PATTERN = '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';
    private const string TOKEN_PATTERN = '/\A([A-Za-z0-9_-]{1,16})\.([A-Za-z0-9_-]{43})\.([A-Za-z0-9_-]{43})\z/';

    /** @var array<string, string> */
    private array $keys;

    /**
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function __construct(private string $activeVersion, string $encodedKeyRing)
    {
        if (1 !== preg_match(self::VERSION_PATTERN, $activeVersion)) {
            throw new InvalidArgumentException('The CSRF active key version is invalid.');
        }

        $decoded = json_decode($encodedKeyRing, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !isset($decoded[$activeVersion]) || !is_string($decoded[$activeVersion])) {
            throw new InvalidArgumentException('The CSRF key ring is invalid.');
        }

        $keys = [];
        foreach ($decoded as $version => $encodedKey) {
            if (!is_string($version) || 1 !== preg_match(self::VERSION_PATTERN, $version) || !is_string($encodedKey)) {
                throw new InvalidArgumentException('The CSRF key ring contains an invalid entry.');
            }

            $key = base64_decode($encodedKey, true);
            if (false === $key || strlen($key) < 32) {
                throw new InvalidArgumentException('Each CSRF HMAC key must contain at least 256 bits.');
            }

            $keys[$version] = $key;
        }

        $this->keys = $keys;
    }

    public function issue(string $sessionId): string
    {
        if (!$this->isSessionId($sessionId)) {
            throw new InvalidArgumentException('The CSRF session id is invalid.');
        }

        $nonce = $this->base64Url(random_bytes(32));

        return $this->activeVersion.'.'.$nonce.'.'.$this->signature($this->activeVersion, $nonce, $sessionId);
    }

    public function verify(string $sessionId, string $publicToken): bool
    {
        if (!$this->isSessionId($sessionId)) {
            return false;
        }

        if (1 !== preg_match(self::TOKEN_PATTERN, $publicToken, $matches)) {
            return false;
        }

        $version = $matches[1];
        $nonce = $matches[2];
        $signature = $matches[3];

        if (!isset($this->keys[$version])) {
            return false;
        }

        return hash_equals($this->signature($version, $nonce, $sessionId), $signature);
    }

    private function signature(string $version, string $nonce, string $sessionId): string
    {
        return $this->base64Url(hash_hmac('sha256', $this->message($version, $nonce, $sessionId), $this->keys[$version], true));
    }

    private function message(string $version, string $nonce, string $sessionId): string
    {
        return 'equo.csrf.v1'.chr(0).$version.chr(0).$nonce.chr(0).strtolower($sessionId);
    }

    private function isSessionId(string $sessionId): bool
    {
        return 1 === preg_match(self::SESSION_ID_PATTERN, $sessionId);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
