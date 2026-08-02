<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Security;

use App\IdentityAccess\Application\Port\PayloadCipherPort;
use JsonException;
use RuntimeException;

final readonly class PayloadCipher implements PayloadCipherPort
{
    private string $key;

    public function __construct(string $encodedKey)
    {
        $key = base64_decode($encodedKey, true);

        if (false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($key)) {
            throw new RuntimeException('The email payload key must contain exactly 256 bits.');
        }

        $this->key = $key;
    }

    public function encrypt(array $payload): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = json_encode($payload, JSON_THROW_ON_ERROR);

        return $nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key);
    }

    /** @return array<string, string> */
    public function decrypt(string $ciphertext): array
    {
        if (strlen($ciphertext) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('The encrypted email payload is invalid.');
        }

        $nonce = substr($ciphertext, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(
            substr($ciphertext, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $nonce,
            $this->key,
        );

        if (false === $plaintext) {
            throw new RuntimeException('The encrypted email payload could not be authenticated.');
        }

        try {
            $payload = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The encrypted email payload is invalid.', previous: $exception);
        }

        if (!is_array($payload) || array_filter($payload, static fn (mixed $value): bool => !is_string($value))) {
            throw new RuntimeException('The encrypted email payload has an invalid shape.');
        }

        return $payload;
    }
}
