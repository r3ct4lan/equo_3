<?php

declare(strict_types=1);

namespace App\Invitations\Adapter\Security;

use App\Invitations\Application\Port\InvitationTokenPort;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class InvitationTokenCodec implements InvitationTokenPort
{
    /** @var array<string, string> */
    private array $keys;

    public function __construct(private string $activeVersion, string $encodedKeyRing)
    {
        try {
            $ring = json_decode($encodedKeyRing, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invitation signing key configuration is invalid.');
        }
        if (!is_array($ring) || [] === $ring || count($ring) > 8) {
            throw new InvalidArgumentException('Invitation signing key configuration is invalid.');
        }
        $keys = [];
        foreach ($ring as $version => $encoded) {
            if (!is_string($version) || !is_string($encoded)) {
                throw new InvalidArgumentException('Invitation signing key configuration is invalid.');
            }
            $key = base64_decode($encoded, true);
            if (false === $key || strlen($key) < 32) {
                throw new InvalidArgumentException('Invitation signing key configuration is invalid.');
            }
            $keys[$version] = $key;
        }
        if (!isset($keys[$activeVersion])) {
            throw new InvalidArgumentException('Invitation active signing key is unavailable.');
        }
        $this->keys = $keys;
    }

    public function issue(string $id): string
    {
        return $this->sign($id, $this->keys[$this->activeVersion]);
    }

    public function reproduce(string $id, string $hash): string
    {
        foreach ($this->keys as $key) {
            $token = $this->sign($id, $key);
            if (hash_equals($hash, $this->digest($token))) {
                return $token;
            }
        }
        throw new RuntimeException('Invitation compatibility key is unavailable.');
    }

    public function verify(string $token): ?string
    {
        if (1 !== preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([A-Za-z0-9_-]{43})$/D', $token, $m)) {
            return null;
        }
        foreach ($this->keys as $key) {
            if (hash_equals($this->sign($m[1], $key), $token)) {
                return $m[1];
            }
        }

        return null;
    }

    public function digest(string $token): string
    {
        return hash('sha256', $token);
    }

    private function sign(string $id, string $key): string
    {
        return $id.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $id, $key, true)), '+/', '-_'), '=');
    }
}
