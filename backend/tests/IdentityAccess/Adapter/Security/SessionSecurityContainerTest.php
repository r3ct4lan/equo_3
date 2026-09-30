<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\AccessTokenVerifierPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SessionSecurityContainerTest extends WebTestCase
{
    public function testSecurityPrimitivePortsResolveFromContainer(): void
    {
        $keys = self::rsaKeyPair();
        self::setEnv('EQUO_JWT_SIGNING_KEY_VERSION', 'v1');
        self::setEnv('EQUO_JWT_SIGNING_PRIVATE_KEY', base64_encode($keys['private']));
        self::setEnv('EQUO_JWT_PUBLIC_KEY_RING', json_encode(['v1' => base64_encode($keys['public'])], JSON_THROW_ON_ERROR));
        self::setEnv('EQUO_JWT_ISSUER', 'https://equo.test');
        self::setEnv('EQUO_JWT_AUDIENCE', 'equo-api');
        self::setEnv('EQUO_JWT_ACCESS_TTL_SECONDS', '900');
        self::setEnv('EQUO_JWT_CLOCK_SKEW_SECONDS', '30');
        self::setEnv('EQUO_CSRF_SIGNING_KEY_VERSION', 'v1');
        self::setEnv('EQUO_CSRF_SIGNING_KEY_RING', '{"v1":"Y3NyZi10b2tlbi1oYW1jLWRldi12MS1rZXktMzItYnl0ZXMh"}');

        self::bootKernel();
        $container = self::getContainer();

        $issuedAccessToken = $container->get(AccessTokenIssuerPort::class)->issue('952adfb3-c5f6-4bd9-87cb-405d456a2f9a');
        self::assertNotNull($container->get(AccessTokenVerifierPort::class)->verify($issuedAccessToken->accessToken));

        $sessionId = '952adfb3-c5f6-4bd9-87cb-405d456a2f9a';
        $issuedRefreshToken = $container->get(RefreshTokenCodecPort::class)->issueForSession($sessionId);
        $parsedRefreshToken = $container->get(RefreshTokenCodecPort::class)->parse($issuedRefreshToken->publicToken);
        self::assertNotNull($parsedRefreshToken);
        self::assertSame($sessionId, $parsedRefreshToken->sessionId);
        self::assertSame($issuedRefreshToken->tokenHash, $parsedRefreshToken->tokenHash);

        $csrfToken = $container->get(CsrfTokenCodecPort::class)->issue($sessionId);
        self::assertTrue($container->get(CsrfTokenCodecPort::class)->verify($sessionId, $csrfToken));
    }

    public function testExistingPublicRoutesAreNotProtectedBySecurityBundle(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/health');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/v1/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertNotContains($client->getResponse()->getStatusCode(), [401, 403]);

        $client->request('POST', '/api/v1/auth/activate', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertNotContains($client->getResponse()->getStatusCode(), [401, 403]);

        $client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertNotContains($client->getResponse()->getStatusCode(), [401, 403]);
    }

    private static function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /** @return array{private: string, public: string} */
    private static function rsaKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);

        self::assertTrue(openssl_pkey_export($key, $privatePem));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        return ['private' => $privatePem, 'public' => $details['key']];
    }
}
