<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\EmailDelivery\Security;

use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PayloadCipherTest extends TestCase
{
    public function testPayloadRoundTripDoesNotExposePlaintext(): void
    {
        $cipher = new PayloadCipher('ZW1haWwtcGF5bG9hZC1kZXYta2V5LTMyLWJ5dGVzISE=');
        $encrypted = $cipher->encrypt(['token' => 'v1.public-secret']);

        self::assertStringNotContainsString('public-secret', $encrypted);
        self::assertSame(['token' => 'v1.public-secret'], $cipher->decrypt($encrypted));
    }

    public function testRepeatedEncryptionUsesIndependentNonces(): void
    {
        $cipher = new PayloadCipher(base64_encode(str_repeat('a', 32)));
        $first = $cipher->encrypt(['token' => 'synthetic-capability']);
        $second = $cipher->encrypt(['token' => 'synthetic-capability']);

        self::assertNotSame($first, $second);
        self::assertSame($cipher->decrypt($first), $cipher->decrypt($second));
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidEncryptionKeyFailsWithoutEchoingIt(string $key): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The email payload key must contain exactly 256 bits.');

        new PayloadCipher($key);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'not base64' => ['%%%private-invalid-key'];
        yield 'empty' => [''];
        yield 'short' => [base64_encode(str_repeat('a', 31))];
        yield 'long' => [base64_encode(str_repeat('a', 33))];
    }

    #[DataProvider('corruptPayloads')]
    public function testCorruptionIsRejectedBeforePayloadCanBeRead(string $case): void
    {
        $cipher = new PayloadCipher(base64_encode(str_repeat('a', 32)));
        $encrypted = $cipher->encrypt(['token' => 'synthetic-capability']);
        $ciphertext = match ($case) {
            'empty' => '',
            'nonce only' => substr($encrypted, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            'truncated' => substr($encrypted, 0, -1),
            'tampered' => substr($encrypted, 0, -1).($encrypted[-1] ^ "\x01"),
            'wrong key' => (new PayloadCipher(base64_encode(str_repeat('b', 32))))->encrypt(['token' => 'synthetic-capability']),
            default => self::fail('Unknown corruption case.'),
        };
        $this->expectException(RuntimeException::class);

        $cipher->decrypt($ciphertext);
    }

    /** @return iterable<string, array{string}> */
    public static function corruptPayloads(): iterable
    {
        foreach (['empty', 'nonce only', 'truncated', 'tampered', 'wrong key'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidPlaintexts')]
    public function testAuthenticatedButInvalidPlaintextIsRejected(string $plaintext, string $message): void
    {
        $key = str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = $nonce.sodium_crypto_secretbox($plaintext, $nonce, $key);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        (new PayloadCipher(base64_encode($key)))->decrypt($ciphertext);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPlaintexts(): iterable
    {
        yield 'invalid json' => ['private-invalid-json', 'The encrypted email payload is invalid.'];
        yield 'scalar' => ['"synthetic-capability"', 'The encrypted email payload has an invalid shape.'];
        yield 'non-string field' => ['{"token":42}', 'The encrypted email payload has an invalid shape.'];
        yield 'nested field' => ['{"token":{"private":"value"}}', 'The encrypted email payload has an invalid shape.'];
    }
}
