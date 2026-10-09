<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Cookie;

final class LogoutHttpTest extends WebTestCase
{
    private const string USER_ID = '89000000-0000-4000-8000-000000000001';
    private const string SESSION_ID = '89000000-0000-4000-8000-000000000002';
    private KernelBrowser $client;
    private Connection $connection;
    private string $privateKey;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privatePem));
        $this->privateKey = $privatePem;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        foreach ([
            'EQUO_JWT_SIGNING_KEY_VERSION' => 'v1', 'EQUO_JWT_SIGNING_PRIVATE_KEY' => base64_encode($privatePem),
            'EQUO_JWT_PUBLIC_KEY_RING' => json_encode(['v1' => base64_encode($details['key'])], JSON_THROW_ON_ERROR),
            'EQUO_JWT_ISSUER' => 'https://equo.test', 'EQUO_JWT_AUDIENCE' => 'equo-api',
            'EQUO_JWT_ACCESS_TTL_SECONDS' => '900', 'EQUO_JWT_CLOCK_SKEW_SECONDS' => '30',
            'EQUO_CSRF_SIGNING_KEY_VERSION' => 'v1',
            'EQUO_CSRF_SIGNING_KEY_RING' => '{"v1":"Y3NyZi1jb25jdXJyZW5jeS10ZXN0LWtleS0zMi1ieXRlcyEhIQ=="}',
            'EQUO_APPLICATION_ORIGIN' => 'https://equo.test',
        ] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testWithoutRefreshCookieIgnoresBearerAndGuardsAndClearsBothCookies(): void
    {
        $this->client->request('POST', '/api/v1/auth/logout', server: ['HTTP_AUTHORIZATION' => 'Bearer invalid']);
        $this->assertSuccess();
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session'));
    }

    #[DataProvider('currentCases')]
    public function testCurrentLogoutLifecycleAndUnusableCredentials(string $case): void
    {
        [$refresh, $csrf] = $this->fixture($case);
        $before = $this->connection->fetchAllAssociative('SELECT id, revoked_at, refresh_token_hash FROM user_session');
        if ('malformed' === $case) {
            $refresh = 'unusable';
        } elseif ('unknown' === $case) {
            $refresh = self::getContainer()->get(RefreshTokenCodecPort::class)->issueForSession('89000000-0000-4000-8000-000000000099')->publicToken;
        } elseif ('stale' === $case) {
            $this->requestCurrent($refresh, $csrf, '/api/v1/auth/refresh');
            self::assertResponseStatusCodeSame(200);
        }

        $this->requestCurrent($refresh, $csrf);
        $this->assertSuccess();
        $after = $this->connection->fetchAllAssociative('SELECT id, revoked_at, refresh_token_hash FROM user_session');
        if (in_array($case, ['active', 'stale'], true)) {
            self::assertNotNull($after[0]['revoked_at']);
            $first = $after[0]['revoked_at'];
            $this->requestCurrent($refresh, $csrf);
            $this->assertSuccess();
            self::assertSame($first, $this->connection->fetchOne('SELECT revoked_at FROM user_session'));
            $this->requestCurrent($refresh, $csrf, '/api/v1/auth/refresh');
            self::assertResponseStatusCodeSame(401);
        } else {
            self::assertSame($before, $after);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function currentCases(): iterable
    {
        foreach (['active', 'stale', 'expired', 'revoked', 'malformed', 'unknown'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('guardCases')]
    public function testForbiddenGuardsDoNotMutateRowsOrCookies(string $case): void
    {
        [$refresh, $csrf] = $this->fixture();
        $server = ['HTTP_ORIGIN' => 'https://equo.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_X_CSRF_TOKEN' => $csrf];
        if ('origin' === $case) {
            $server['HTTP_ORIGIN'] = 'https://evil.test';
        } elseif ('missing-origin' === $case) {
            unset($server['HTTP_ORIGIN']);
        } elseif ('fetch-site' === $case) {
            $server['HTTP_SEC_FETCH_SITE'] = 'same-site';
        } elseif ('header' === $case) {
            unset($server['HTTP_X_CSRF_TOKEN']);
        } elseif ('mismatch' === $case) {
            $server['HTTP_X_CSRF_TOKEN'] = 'mismatch';
        } elseif ('empty-cookie' === $case) {
            $refresh = '';
            unset($server['HTTP_X_CSRF_TOKEN']);
        } elseif ('missing-csrf-cookie' === $case) {
            $csrf = '';
        } else {
            $csrf = 'binding' === $case
                ? self::getContainer()->get(CsrfTokenCodecPort::class)->issue('89000000-0000-4000-8000-000000000099')
                : 'v1.'.str_repeat('a', 43).'.'.str_repeat('b', 43);
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }
        $this->cookies($refresh, $csrf);
        $this->client->request('POST', '/api/v1/auth/logout', server: $server);
        $this->assertFailure(403, 'FORBIDDEN');
        self::assertNull($this->connection->fetchOne('SELECT revoked_at FROM user_session'));
        if ('' !== $csrf) {
            self::assertStringNotContainsString($csrf, (string) $this->client->getResponse()->getContent());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function guardCases(): iterable
    {
        foreach (['origin', 'missing-origin', 'fetch-site', 'header', 'mismatch', 'signature', 'binding', 'empty-cookie', 'missing-csrf-cookie'] as $case) {
            yield $case => [$case];
        }
    }

    public function testLogoutAllUsesOnlyAuthenticatedUserAndDoesNotBlacklistJwt(): void
    {
        $this->fixture();
        $access = self::getContainer()->get(AccessTokenIssuerPort::class)->issue(self::USER_ID)->accessToken;
        $this->client->getCookieJar()->clear();
        foreach ([1, 2] as $_) {
            $this->client->request('POST', '/api/v1/auth/logout-all', parameters: ['currentUserId' => 'foreign'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$access]);
            $this->assertSuccess();
            self::assertNotNull($this->connection->fetchOne('SELECT revoked_at FROM user_session'));
        }
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$access]);
        self::assertResponseStatusCodeSame(200);
    }

    #[DataProvider('bearerCases')]
    public function testLogoutAllUniformAuthenticationFailure(string $case): void
    {
        $this->fixture();
        $access = self::getContainer()->get(AccessTokenIssuerPort::class)->issue('unknown' === $case ? '89000000-0000-4000-8000-000000000099' : self::USER_ID)->accessToken;
        if ('inactive' === $case) {
            $this->connection->executeStatement('UPDATE app_user SET is_active = false');
        }
        if ('expired' === $case) {
            $segments = explode('.', $access);
            $payload = json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
            $now = self::getContainer()->get(ClockPort::class)->now()->getTimestamp();
            $payload['iat'] = $payload['nbf'] = $now - 3600;
            $payload['exp'] = $now - 2700;
            $input = $segments[0].'.'.rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            self::assertTrue(openssl_sign($input, $signature, $this->privateKey, OPENSSL_ALGO_SHA256));
            $access = $input.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        }
        $server = match ($case) {
            'missing' => [],
            'malformed' => ['HTTP_AUTHORIZATION' => 'Basic invalid'],
            'invalid' => ['HTTP_AUTHORIZATION' => 'Bearer invalid'],
            default => ['HTTP_AUTHORIZATION' => 'Bearer '.$access],
        };
        $this->client->request('POST', '/api/v1/auth/logout-all', server: $server);
        $this->assertFailure(401, 'AUTHENTICATION_REQUIRED');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertNull($this->connection->fetchOne('SELECT revoked_at FROM user_session'));
        self::assertStringNotContainsString($access, (string) $this->client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function bearerCases(): iterable
    {
        foreach (['missing', 'malformed', 'invalid', 'expired', 'unknown', 'inactive'] as $case) {
            yield $case => [$case];
        }
    }

    public function testLogoutAllWithNoSessionsReturns204AndClearsCookies(): void
    {
        $this->fixture();
        $access = self::getContainer()->get(AccessTokenIssuerPort::class)->issue(self::USER_ID)->accessToken;
        $this->connection->executeStatement('DELETE FROM user_session');
        $this->client->request('POST', '/api/v1/auth/logout-all', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$access]);
        $this->assertSuccess();
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session'));
    }

    /** @return array{string, string} */
    private function fixture(string $state = 'active'): array
    {
        $now = self::getContainer()->get(ClockPort::class)->now();
        $this->connection->insert('app_user', [
            'id' => self::USER_ID, 'name' => 'Logout User', 'email' => 'logout@example.test',
            'password_hash' => 'not-used', 'is_active' => 1, 'created_at' => $now->modify('-1 day')->format('Y-m-d H:i:sP'),
        ]);
        $issued = self::getContainer()->get(RefreshTokenCodecPort::class)->issueForSession(self::SESSION_ID);
        $this->connection->insert('user_session', [
            'id' => self::SESSION_ID, 'user_id' => self::USER_ID, 'refresh_token_hash' => $issued->tokenHash,
            'created_at' => $now->modify('-31 days')->format('Y-m-d H:i:sP'),
            'expires_at' => $now->modify('expired' === $state ? '-1 second' : '+1 day')->format('Y-m-d H:i:sP'),
            'revoked_at' => 'revoked' === $state ? $now->modify('-1 hour')->format('Y-m-d H:i:sP') : null,
        ]);

        return [$issued->publicToken, self::getContainer()->get(CsrfTokenCodecPort::class)->issue(self::SESSION_ID)];
    }

    private function requestCurrent(string $refresh, string $csrf, string $path = '/api/v1/auth/logout'): void
    {
        $this->cookies($refresh, $csrf);
        $this->client->request('POST', $path, server: ['HTTP_ORIGIN' => 'https://equo.test', 'HTTP_X_CSRF_TOKEN' => $csrf]);
    }

    private function cookies(string $refresh, string $csrf): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new BrowserKitCookie('equo_refresh', $refresh, null, '/api/v1/auth', 'localhost', false));
        $this->client->getCookieJar()->set(new BrowserKitCookie('__Host-equo_csrf', $csrf, null, '/', 'localhost', false));
    }

    private function assertSuccess(): void
    {
        self::assertResponseStatusCodeSame(204);
        self::assertResponseHasHeader('X-Request-Id');
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertFalse($this->client->getResponse()->headers->has('Content-Type'));
        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(2, $cookies);
        foreach ($cookies as $cookie) {
            self::assertContains($cookie->getName(), ['equo_refresh', '__Host-equo_csrf']);
            self::assertSame('equo_refresh' === $cookie->getName() ? '/api/v1/auth' : '/', $cookie->getPath());
            self::assertSame('equo_refresh' === $cookie->getName(), $cookie->isHttpOnly());
            self::assertTrue($cookie->isSecure());
            self::assertNull($cookie->getDomain());
            self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
            self::assertSame(0, $cookie->getMaxAge());
        }
    }

    private function assertFailure(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['error'], array_keys($body));
        self::assertSame(['code', 'message', 'requestId'], array_keys($body['error']));
        self::assertSame($code, $body['error']['code']);
        self::assertSame($this->client->getResponse()->headers->get('X-Request-Id'), $body['error']['requestId']);
    }
}
