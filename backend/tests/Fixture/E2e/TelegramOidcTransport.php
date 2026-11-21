<?php

declare(strict_types=1);

namespace App\Tests\Fixture\E2e;

use Firebase\JWT\JWT;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelegramOidcTransport
{
    public static function create(string $clientId): MockHttpClient
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $public = false === $key ? false : openssl_pkey_get_details($key);
        if (false === $key || !is_array($public) || !is_string($public['rsa']['n'] ?? null) || !is_string($public['rsa']['e'] ?? null)) {
            throw new RuntimeException('Could not initialize the E2E Telegram provider.');
        }

        $kid = 'equo-e2e-telegram';
        $jwks = ['keys' => [[
            'kid' => $kid, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => self::base64Url($public['rsa']['n']),
            'e' => self::base64Url($public['rsa']['e']),
        ]]];

        return new MockHttpClient(static function (string $method, string $url, array $options) use ($clientId, $key, $kid, $jwks): MockResponse {
            if ('GET' === $method && 'https://oauth.telegram.org/.well-known/jwks.json' === $url) {
                return new MockResponse(json_encode($jwks, JSON_THROW_ON_ERROR));
            }
            if ('POST' !== $method || 'https://oauth.telegram.org/token' !== $url || !is_string($options['body'] ?? null)) {
                return new MockResponse('{}', ['http_code' => 400]);
            }

            parse_str($options['body'], $data);
            $code = $data['code'] ?? null;
            $verifier = $data['code_verifier'] ?? null;
            if (!is_string($code) || !is_string($verifier) || 1 !== preg_match('/\A([1-9][0-9]{1,15})\.([A-Za-z0-9_-]{43})\z/D', $code, $parts)
                || !hash_equals($parts[2], self::base64Url(hash('sha256', $verifier, true)))) {
                return new MockResponse('{}', ['http_code' => 400]);
            }

            $now = time();
            $claims = [
                'iss' => 'https://oauth.telegram.org', 'sub' => 'e2e-provider-'.$parts[1], 'aud' => $clientId,
                'iat' => $now, 'exp' => $now + 60, 'id' => (int) $parts[1], 'name' => 'Telegram Fixture '.$parts[1],
            ];

            return new MockResponse(json_encode(['id_token' => JWT::encode($claims, $key, 'RS256', $kid)], JSON_THROW_ON_ERROR));
        });
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
