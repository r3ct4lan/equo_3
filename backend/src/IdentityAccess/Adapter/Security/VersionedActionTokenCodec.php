<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\IssuedActionToken;
use InvalidArgumentException;
use JsonException;

final readonly class VersionedActionTokenCodec implements ActionTokenCodecPort
{
    /** @var array<string, string> */
    private array $keys;

    /** @throws JsonException */
    public function __construct(private string $activeVersion, string $encodedKeyRing)
    {
        $decoded = json_decode($encodedKeyRing, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !isset($decoded[$activeVersion]) || !is_string($decoded[$activeVersion])) {
            throw new InvalidArgumentException('The action-token key ring is invalid.');
        }

        $keys = [];
        foreach ($decoded as $version => $encodedKey) {
            if (!is_string($version) || !preg_match('/\A[A-Za-z0-9_-]{1,16}\z/', $version) || !is_string($encodedKey)) {
                throw new InvalidArgumentException('The action-token key ring contains an invalid entry.');
            }

            $key = base64_decode($encodedKey, true);
            if (false === $key || strlen($key) < 32) {
                throw new InvalidArgumentException('Each action-token HMAC key must contain at least 256 bits.');
            }
            $keys[$version] = $key;
        }

        $this->keys = $keys;
    }

    public function issue(): IssuedActionToken
    {
        $publicToken = $this->activeVersion.'.'.$this->base64Url(random_bytes(32));

        return new IssuedActionToken($publicToken, $this->digestForVersion($publicToken, $this->activeVersion));
    }

    public function digest(string $publicToken): ?string
    {
        if (1 !== preg_match('/\A([A-Za-z0-9_-]{1,16})\.([A-Za-z0-9_-]{43})\z/', $publicToken, $matches)) {
            return null;
        }

        $version = $matches[1];

        if (!isset($this->keys[$version])) {
            return null;
        }

        return $this->digestForVersion($publicToken, $version);
    }

    private function digestForVersion(string $publicToken, string $version): string
    {
        return $version.':'.$this->base64Url(hash_hmac('sha256', $publicToken, $this->keys[$version], true));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
