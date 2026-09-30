<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Security\VersionedCsrfTokenCodec;
use App\IdentityAccess\Application\Port\AccessTokenVerifierPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class RefreshHttpTest extends WebTestCase
{
    private const string ORIGIN = 'https://equo.test';
    private const string CSRF_KEY_RING = '{"v1":"b2xkLWNzcmYta2V5LWZvci1yZXRhaW5lZC10ZXN0cy0zMiE=","v2":"bmV3LWNzcmYta2V5LWZvci1yZWZyZXNoLXRlc3RzLTMyIQ=="}';

    private KernelBrowser $client;
    private Connection $connection;
    private PasswordHashingPort $passwordHasher;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->configureJwtEnvironment();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->passwordHasher = self::getContainer()->get(PasswordHashingPort::class);
        $this->connection->beginTransaction();
        self::getContainer()->get('test.rate_limiter_cache')->clear();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testSuccessfulRefreshRotatesCookiesAndKeepsSessionExpiry(): void
    {
        $credentials = $this->loginActiveUser('refresh-success@example.test');
        $oldHash = $this->storedHash();
        $oldExpiresAt = $this->storedTime('expires_at');

        $body = $this->refresh($credentials['refresh'], $credentials['csrf']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHasHeader('X-Request-Id');
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['accessToken', 'expiresIn'], $this->sortedKeys($body));
        self::assertSame(900, $body['expiresIn']);
        self::assertNotNull(self::getContainer()->get(AccessTokenVerifierPort::class)->verify($body['accessToken']));
        self::assertSame(1, $this->countSessions());

        $cookies = $this->responseCookies();
        self::assertArrayHasKey('equo_refresh', $cookies);
        self::assertArrayHasKey('__Host-equo_csrf', $cookies);
        $this->assertCookie($cookies['equo_refresh'], httpOnly: true, path: '/api/v1/auth', expiresTime: $credentials['refreshExpires']);
        $this->assertCookie($cookies['__Host-equo_csrf'], httpOnly: false, path: '/', expiresTime: $credentials['csrfExpires']);
        self::assertNotSame($credentials['refresh'], $cookies['equo_refresh']->getValue());
        self::assertNotSame($credentials['csrf'], $cookies['__Host-equo_csrf']->getValue());
        self::assertSame($oldExpiresAt, $this->storedTime('expires_at'));
        self::assertNotSame($oldHash, $this->storedHash());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ?', [$oldHash]));
    }

    public function testRefreshRequiresNoRequestBodyOrContentType(): void
    {
        $credentials = $this->loginActiveUser('refresh-no-body@example.test');
        $this->prepareCookies($credentials['refresh'], $credentials['csrf']);
        $this->client->request(
            'POST',
            '/api/v1/auth/refresh',
            server: $this->refreshServer(csrfHeader: $credentials['csrf']),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    #[DataProvider('invalidRefreshProvider')]
    public function testInvalidRefreshTokenBranchesReturnOnePublicError(string $token, ?string $sessionState): void
    {
        $credentials = match ($sessionState) {
            'expired' => $this->sessionFixture('expired'),
            'revoked' => $this->sessionFixture('revoked'),
            default => ['refresh' => $token, 'csrf' => 'csrf.public'],
        };
        $oldHash = $this->countSessions() > 0 ? $this->storedHash() : null;

        $body = $this->refresh($credentials['refresh'], $credentials['csrf']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('INVALID_REFRESH_TOKEN', $this->error($body)['code']);
        self::assertStringNotContainsString($credentials['refresh'], json_encode($body, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        if (null !== $oldHash) {
            self::assertSame($oldHash, $this->storedHash());
        }
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function invalidRefreshProvider(): iterable
    {
        yield 'malformed' => ['not-a-refresh-token', null];
        yield 'unknown' => ['rt2.85000000-0000-4000-8000-000000000099.'.str_repeat('A', 43), null];
        yield 'expired' => ['', 'expired'];
        yield 'revoked' => ['', 'revoked'];
    }

    public function testOldTokenAfterRotationIsRejectedAndDoesNotRestoreOldHash(): void
    {
        $credentials = $this->loginActiveUser('refresh-replay@example.test');
        $oldHash = $this->storedHash();

        $this->refresh($credentials['refresh'], $credentials['csrf']);
        $newHash = $this->storedHash();
        self::assertNotSame($oldHash, $newHash);

        $body = $this->refresh($credentials['refresh'], $credentials['csrf']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('INVALID_REFRESH_TOKEN', $this->error($body)['code']);
        self::assertSame($newHash, $this->storedHash());
    }

    #[DataProvider('forbiddenOriginProvider')]
    public function testSameOriginGuardRejectsInvalidOrigins(?string $origin): void
    {
        $credentials = $this->loginActiveUser('refresh-origin@example.test');
        $oldHash = $this->storedHash();

        $body = $this->refresh($credentials['refresh'], $credentials['csrf'], origin: $origin);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('FORBIDDEN', $this->error($body)['code']);
        self::assertSame($oldHash, $this->storedHash());
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertNotSame('*', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    /** @return iterable<string, array{string|null}> */
    public static function forbiddenOriginProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'wrong scheme' => ['http://equo.test'];
        yield 'wrong host' => ['https://evil.test'];
        yield 'wrong port' => ['https://equo.test:8443'];
        yield 'prefix' => ['https://equo.test.evil.test'];
        yield 'suffix' => ['https://evil.test/equo.test'];
        yield 'null' => ['null'];
        yield 'wildcard' => ['*'];
    }

    #[DataProvider('fetchSiteProvider')]
    public function testFetchMetadataPolicy(?string $fetchSite, bool $allowed): void
    {
        $credentials = $this->loginActiveUser('refresh-fetch-'.bin2hex(random_bytes(3)).'@example.test');
        $oldHash = $this->storedHash();

        $body = $this->refresh($credentials['refresh'], $credentials['csrf'], fetchSite: $fetchSite);

        if ($allowed) {
            self::assertResponseStatusCodeSame(Response::HTTP_OK);
            self::assertNotSame($oldHash, $this->storedHash());

            return;
        }

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('FORBIDDEN', $this->error($body)['code']);
        self::assertSame($oldHash, $this->storedHash());
    }

    /** @return iterable<string, array{string|null, bool}> */
    public static function fetchSiteProvider(): iterable
    {
        yield 'missing' => [null, true];
        yield 'same-origin' => ['same-origin', true];
        yield 'none' => ['none', true];
        yield 'same-site' => ['same-site', false];
        yield 'cross-site' => ['cross-site', false];
        yield 'empty' => ['', false];
        yield 'unknown' => ['navigate', false];
    }

    #[DataProvider('csrfFailureProvider')]
    public function testCsrfFailuresReturnForbiddenBeforeOrWithoutRotation(string $case): void
    {
        $credentials = $this->loginActiveUser('refresh-csrf-'.$case.'@example.test');
        $oldHash = $this->storedHash();
        $sessionId = (string) $this->connection->fetchOne('SELECT id FROM user_session');
        $badCsrf = match ($case) {
            'malformed' => 'not-a-csrf-token',
            'bad-signature' => 'v2.'.str_repeat('a', 43).'.'.str_repeat('b', 43),
            'other-session' => self::getContainer()->get(CsrfTokenCodecPort::class)->issue('85000000-0000-4000-8000-000000000999'),
            default => $credentials['csrf'],
        };

        $body = match ($case) {
            'missing-cookie' => $this->refresh($credentials['refresh'], null),
            'missing-header' => $this->refresh($credentials['refresh'], $credentials['csrf'], includeCsrfHeader: false),
            'mismatch' => $this->refresh($credentials['refresh'], 'other-csrf', csrfHeader: $credentials['csrf']),
            default => $this->refresh($credentials['refresh'], $badCsrf),
        };

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('FORBIDDEN', $this->error($body)['code']);
        self::assertSame($oldHash, $this->storedHash());
        self::assertSame($sessionId, $this->connection->fetchOne('SELECT id FROM user_session'));
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    /** @return iterable<string, array{string}> */
    public static function csrfFailureProvider(): iterable
    {
        yield 'missing cookie' => ['missing-cookie'];
        yield 'missing header' => ['missing-header'];
        yield 'mismatch' => ['mismatch'];
        yield 'malformed' => ['malformed'];
        yield 'bad signature' => ['bad-signature'];
        yield 'other session' => ['other-session'];
    }

    public function testRetainedOldCsrfKeyStillVerifiesWhenPresentInKeyRing(): void
    {
        $credentials = $this->loginActiveUser('refresh-old-csrf@example.test');
        $sessionId = (string) $this->connection->fetchOne('SELECT id FROM user_session');
        $oldKeyCsrf = (new VersionedCsrfTokenCodec('v1', self::CSRF_KEY_RING))->issue($sessionId);

        $body = $this->refresh($credentials['refresh'], $oldKeyCsrf);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['accessToken', 'expiresIn'], $this->sortedKeys($body));
    }

    public function testInactiveOwnerDoesNotRotate(): void
    {
        $credentials = $this->loginActiveUser('refresh-inactive@example.test');
        $oldHash = $this->storedHash();
        $this->connection->executeStatement('UPDATE app_user SET is_active = false');

        $body = $this->refresh($credentials['refresh'], $credentials['csrf']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('ACCOUNT_INACTIVE', $this->error($body)['code']);
        self::assertSame($oldHash, $this->storedHash());
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    /** @return array{refresh: string, csrf: string} */
    private function sessionFixture(string $state): array
    {
        $sessionId = '85000000-0000-4000-8000-'.sprintf('%012d', random_int(1, 999999999999));
        $userId = '85000000-0000-4000-8001-'.sprintf('%012d', random_int(1, 999999999999));
        $issued = self::getContainer()->get(RefreshTokenCodecPort::class)->issueForSession($sessionId);
        $createdAt = 'expired' === $state ? new DateTimeImmutable('2026-06-01T12:00:00Z') : new DateTimeImmutable('2026-08-08T12:00:00Z');
        $expiresAt = 'expired' === $state ? new DateTimeImmutable('2026-07-01T12:00:00Z') : $createdAt->modify('+30 days');
        $revokedAt = 'revoked' === $state ? $createdAt->modify('+1 hour') : null;
        $this->insertUser($userId, $userId.'@example.test', true, 'Correct password!');
        $this->connection->insert('user_session', [
            'id' => $sessionId,
            'user_id' => $userId,
            'refresh_token_hash' => $issued->tokenHash,
            'created_at' => $createdAt->format('Y-m-d H:i:sP'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:sP'),
            'revoked_at' => $revokedAt?->format('Y-m-d H:i:sP'),
        ]);

        return [
            'refresh' => $issued->publicToken,
            'csrf' => self::getContainer()->get(CsrfTokenCodecPort::class)->issue($sessionId),
        ];
    }

    /** @return array{refresh: string, csrf: string, refreshExpires: int, csrfExpires: int} */
    private function loginActiveUser(string $email): array
    {
        $userId = '85000000-0000-4000-8000-'.sprintf('%012d', random_int(1, 999999999999));
        $this->insertUser($userId, $email, true, 'Correct password!');
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.10'],
            content: json_encode(['email' => $email, 'password' => 'Correct password!'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $cookies = $this->responseCookies();

        return [
            'refresh' => $this->cookieValue($cookies['equo_refresh']),
            'csrf' => $this->cookieValue($cookies['__Host-equo_csrf']),
            'refreshExpires' => $cookies['equo_refresh']->getExpiresTime(),
            'csrfExpires' => $cookies['__Host-equo_csrf']->getExpiresTime(),
        ];
    }

    /** @return array<string, mixed> */
    private function refresh(
        string $refreshToken,
        ?string $csrfCookie,
        ?string $origin = self::ORIGIN,
        ?string $fetchSite = 'same-origin',
        bool $includeCsrfHeader = true,
        ?string $csrfHeader = null,
    ): array {
        $this->client->getCookieJar()->clear();
        $this->prepareCookies($refreshToken, $csrfCookie);
        $this->client->request(
            'POST',
            '/api/v1/auth/refresh',
            server: $this->refreshServer($origin, $fetchSite, $includeCsrfHeader, $csrfHeader ?? $csrfCookie),
        );

        return $this->jsonBody();
    }

    /** @return array<string, string> */
    private function refreshServer(
        ?string $origin = self::ORIGIN,
        ?string $fetchSite = 'same-origin',
        bool $includeCsrfHeader = true,
        ?string $csrfHeader = null,
    ): array {
        $server = ['REMOTE_ADDR' => '192.0.2.20'];
        if (null !== $origin) {
            $server['HTTP_ORIGIN'] = $origin;
        }
        if (null !== $fetchSite) {
            $server['HTTP_SEC_FETCH_SITE'] = $fetchSite;
        }
        if ($includeCsrfHeader) {
            $server['HTTP_X_CSRF_TOKEN'] = (string) $csrfHeader;
        }

        return $server;
    }

    private function prepareCookies(string $refreshToken, ?string $csrfCookie): void
    {
        $this->client->getCookieJar()->set(new BrowserKitCookie('equo_refresh', $refreshToken, null, '/api/v1/auth', 'localhost', false));
        if (null !== $csrfCookie) {
            $this->client->getCookieJar()->set(new BrowserKitCookie('__Host-equo_csrf', $csrfCookie, null, '/', 'localhost', false));
        }
    }

    private function insertUser(string $id, string $email, bool $active, string $password): void
    {
        $this->connection->insert('app_user', [
            'id' => $id,
            'name' => 'Refresh User',
            'email' => $email,
            'password_hash' => $this->passwordHasher->hash($password),
            'is_active' => $active ? 1 : 0,
            'created_at' => '2026-08-08 12:00:00+00',
        ]);
    }

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function error(array $body): array
    {
        self::assertArrayHasKey('error', $body);
        self::assertIsArray($body['error']);

        return $body['error'];
    }

    /** @return array<string, Cookie> */
    private function responseCookies(): array
    {
        $cookies = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        return $cookies;
    }

    private function cookieValue(Cookie $cookie): string
    {
        $value = $cookie->getValue();
        self::assertIsString($value);

        return $value;
    }

    private function assertCookie(Cookie $cookie, bool $httpOnly, string $path, int $expiresTime): void
    {
        self::assertSame($httpOnly, $cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame($path, $cookie->getPath());
        self::assertNull($cookie->getDomain());
        self::assertSame($expiresTime, $cookie->getExpiresTime());
    }

    private function storedHash(): string
    {
        $hash = $this->connection->fetchOne('SELECT refresh_token_hash FROM user_session');
        self::assertIsString($hash);

        return $hash;
    }

    private function storedTime(string $field): string
    {
        $value = $this->connection->fetchOne("SELECT to_char($field, 'YYYY-MM-DD HH24:MI:SSOF') FROM user_session");
        self::assertIsString($value);

        return $value;
    }

    private function countSessions(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session');
    }

    /** @param array<string, mixed> $values
     * @return list<string>
     */
    private function sortedKeys(array $values): array
    {
        $keys = array_keys($values);
        sort($keys);

        return $keys;
    }

    private function configureJwtEnvironment(): void
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

        $this->setEnv('EQUO_JWT_SIGNING_KEY_VERSION', 'v1');
        $this->setEnv('EQUO_JWT_SIGNING_PRIVATE_KEY', base64_encode($privatePem));
        $this->setEnv('EQUO_JWT_PUBLIC_KEY_RING', json_encode(['v1' => base64_encode($details['key'])], JSON_THROW_ON_ERROR));
        $this->setEnv('EQUO_JWT_ISSUER', 'https://equo.test');
        $this->setEnv('EQUO_JWT_AUDIENCE', 'equo-api');
        $this->setEnv('EQUO_JWT_ACCESS_TTL_SECONDS', '900');
        $this->setEnv('EQUO_JWT_CLOCK_SKEW_SECONDS', '30');
        $this->setEnv('EQUO_CSRF_SIGNING_KEY_VERSION', 'v2');
        $this->setEnv('EQUO_CSRF_SIGNING_KEY_RING', self::CSRF_KEY_RING);
        $this->setEnv('EQUO_APPLICATION_ORIGIN', self::ORIGIN);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
