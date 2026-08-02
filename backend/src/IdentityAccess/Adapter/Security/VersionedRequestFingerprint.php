<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\RequestFingerprintPort;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

final readonly class VersionedRequestFingerprint implements RequestFingerprintPort
{
    /** @var array<string, string> */
    private array $keys;

    /** @throws JsonException */
    public function __construct(private string $activeVersion, string $encodedKeyRing)
    {
        $decoded = json_decode($encodedKeyRing, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !isset($decoded[$activeVersion]) || !is_string($decoded[$activeVersion])) {
            throw new InvalidArgumentException('The idempotency fingerprint key ring is invalid.');
        }

        $keys = [];
        foreach ($decoded as $version => $encodedKey) {
            if (!is_string($version) || !preg_match('/\A[A-Za-z0-9_-]{1,16}\z/', $version) || !is_string($encodedKey)) {
                throw new InvalidArgumentException('The idempotency fingerprint key ring contains an invalid entry.');
            }

            $key = base64_decode($encodedKey, true);
            if (false === $key || strlen($key) < 32) {
                throw new InvalidArgumentException('Each idempotency fingerprint HMAC key must contain at least 256 bits.');
            }
            $keys[$version] = $key;
        }

        $this->keys = $keys;
    }

    public function create(string $canonicalRequest): string
    {
        return $this->digestForVersion($canonicalRequest, $this->activeVersion);
    }

    public function matches(string $storedFingerprint, string $canonicalRequest): bool
    {
        if (1 === preg_match('/\A([A-Za-z0-9_-]{1,16}):([A-Za-z0-9_-]{43})\z/', $storedFingerprint, $matches)) {
            $version = $matches[1];

            if (!isset($this->keys[$version])) {
                throw new UnexpectedValueException('The stored idempotency fingerprint uses an unavailable key version.');
            }

            return hash_equals($storedFingerprint, $this->digestForVersion($canonicalRequest, $version));
        }

        // Transitional compatibility for pre-ADR-019 records. Such records are
        // never created again and are restarted with HMAC after their 24h TTL.
        return 1 === preg_match('/\A[a-f0-9]{64}\z/', $storedFingerprint)
            && hash_equals($storedFingerprint, hash('sha256', $canonicalRequest));
    }

    private function digestForVersion(string $canonicalRequest, string $version): string
    {
        return $version.':'.$this->base64Url(hash_hmac('sha256', $canonicalRequest, $this->keys[$version], true));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
