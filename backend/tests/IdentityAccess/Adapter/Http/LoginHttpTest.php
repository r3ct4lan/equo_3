<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenVerifierPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class LoginHttpTest extends WebTestCase
{
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

    public function testSuccessfulLoginMatchesHttpContractAndCreatesSession(): void
    {
        $userId = '83000000-0000-4000-8000-000000000001';
        $this->insertUser($userId, 'login@example.test', true, 'Correct password!');

        $body = $this->login('  Login@Example.Test  ', 'Correct password!');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHasHeader('X-Request-Id');
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['accessToken', 'expiresIn', 'user'], $this->sortedKeys($body));
        self::assertSame(900, $body['expiresIn']);
        self::assertSame(['email', 'id', 'isActive', 'name'], $this->sortedKeys($body['user']));
        self::assertSame($userId, $body['user']['id']);
        self::assertSame('Login User', $body['user']['name']);
        self::assertSame('login@example.test', $body['user']['email']);
        self::assertTrue($body['user']['isActive']);
        self::assertNotNull(self::getContainer()->get(AccessTokenVerifierPort::class)->verify($body['accessToken']));
        self::assertSame(1, $this->countSessions());

        $cookies = $this->responseCookies();
        self::assertArrayHasKey('equo_refresh', $cookies);
        self::assertArrayHasKey('__Host-equo_csrf', $cookies);
        $this->assertCookie($cookies['equo_refresh'], httpOnly: true, path: '/api/v1/auth');
        $this->assertCookie($cookies['__Host-equo_csrf'], httpOnly: false, path: '/');

        $refreshToken = $cookies['equo_refresh']->getValue();
        $csrfToken = $cookies['__Host-equo_csrf']->getValue();
        self::assertIsString($refreshToken);
        self::assertIsString($csrfToken);
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('createdAt', $encoded);
        self::assertStringNotContainsString('sessionId', $encoded);
        self::assertStringNotContainsString('refreshToken', $encoded);
        self::assertStringNotContainsString('csrfToken', $encoded);
        self::assertStringNotContainsString($refreshToken, $encoded);
        self::assertStringNotContainsString($csrfToken, $encoded);

        $storedHash = $this->connection->fetchOne('SELECT refresh_token_hash FROM user_session');
        self::assertIsString($storedHash);
        self::assertStringStartsWith('sha256:', $storedHash);
        self::assertSame($storedHash, self::getContainer()->get(RefreshTokenCodecPort::class)->digest($refreshToken));
        self::assertStringNotContainsString($refreshToken, $storedHash);
    }

    public function testUnknownWrongAndInactiveCredentialsUseSafeErrorsWithoutCookiesOrSessions(): void
    {
        $this->insertUser('83000000-0000-4000-8000-000000000011', 'active@example.test', true, 'Correct password!');
        $this->insertUser('83000000-0000-4000-8000-000000000012', 'inactive@example.test', false, 'Correct password!');

        $unknown = $this->login('unknown@example.test', 'Wrong password!');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $unknownError = $this->errorWithoutRequestId($unknown);
        self::assertSame('INVALID_CREDENTIALS', $unknownError['code']);
        $this->assertNoCookiesOrSessions();

        $wrong = $this->login('active@example.test', 'Wrong password!');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame($unknownError, $this->errorWithoutRequestId($wrong));
        $this->assertNoCookiesOrSessions();

        $inactiveWrong = $this->login('inactive@example.test', 'Wrong password!');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame($unknownError, $this->errorWithoutRequestId($inactiveWrong));
        $this->assertNoCookiesOrSessions();

        $inactive = $this->login('inactive@example.test', 'Correct password!');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('ACCOUNT_INACTIVE', $this->error($inactive)['code']);
        $this->assertNoCookiesOrSessions();
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloadProvider')]
    public function testValidationErrorsDoNotIssueCookiesOrSessions(array $payload, int $status, string $code): void
    {
        $this->jsonRequest($payload);

        self::assertResponseStatusCodeSame($status);
        self::assertSame($code, $this->error($this->jsonBody())['code']);
        $this->assertNoCookiesOrSessions();
    }

    /** @return iterable<string, array{array<string, mixed>, int, string}> */
    public static function invalidPayloadProvider(): iterable
    {
        yield 'missing email' => [['password' => 'Correct password!'], 422, 'VALIDATION_ERROR'];
        yield 'invalid email' => [['email' => 'not-an-email', 'password' => 'Correct password!'], 422, 'VALIDATION_ERROR'];
        yield 'missing password' => [['email' => 'user@example.test'], 422, 'VALIDATION_ERROR'];
        yield 'wrong email type' => [['email' => [], 'password' => 'Correct password!'], 422, 'VALIDATION_ERROR'];
        yield 'unknown field' => [['email' => 'user@example.test', 'password' => 'Correct password!', 'role' => 'admin'], 400, 'INVALID_REQUEST'];
    }

    public function testMalformedJsonAndWrongContentTypeUseCommonHttpErrors(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"broken":',
        );
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('INVALID_JSON', $this->error($this->jsonBody())['code']);
        $this->assertNoCookiesOrSessions();

        $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'text/plain'], content: 'email=x');
        self::assertResponseStatusCodeSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        self::assertSame('UNSUPPORTED_MEDIA_TYPE', $this->error($this->jsonBody())['code']);
        $this->assertNoCookiesOrSessions();
    }

    public function testLoginEmailIpRateLimitUsesFivePerFifteenMinutes(): void
    {
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->login('same@example.test', 'Wrong password!', '198.51.100.1');
        }

        $this->assertRateLimitError();
        $this->assertNoCookiesOrSessions();
    }

    public function testLoginIpRateLimitUsesThirtyPerFifteenMinutes(): void
    {
        for ($attempt = 1; $attempt <= 31; ++$attempt) {
            $this->login(sprintf('unknown-%02d@example.test', $attempt), 'Wrong password!', '198.51.100.2');
        }

        $this->assertRateLimitError();
        $this->assertNoCookiesOrSessions();
    }

    public function testLoginRouteIsPublicRefreshExistsAndMeIsStillAbsent(): void
    {
        $this->jsonRequest([]);
        self::assertNotContains($this->client->getResponse()->getStatusCode(), [401, 403]);

        $this->client->request('POST', '/api/v1/auth/refresh', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('AUTHENTICATION_REQUIRED', $this->error($this->jsonBody())['code']);

        $this->client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @return array<string, mixed> */
    private function login(string $email, string $password, string $ip = '192.0.2.1'): array
    {
        $this->jsonRequest(['email' => $email, 'password' => $password], $ip);

        return $this->jsonBody();
    }

    /** @param array<string, mixed> $payload */
    private function jsonRequest(array $payload, string $ip = '192.0.2.1'): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function insertUser(string $id, string $email, bool $active, string $password): void
    {
        $this->connection->insert('app_user', [
            'id' => $id,
            'name' => 'Login User',
            'email' => $email,
            'password_hash' => $this->passwordHasher->hash($password),
            'is_active' => $active ? 1 : 0,
            'created_at' => (new DateTimeImmutable('2026-08-08T12:00:00Z'))->format('Y-m-d H:i:sP'),
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

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function errorWithoutRequestId(array $body): array
    {
        $error = $this->error($body);
        unset($error['requestId']);

        return $error;
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

    private function assertCookie(Cookie $cookie, bool $httpOnly, string $path): void
    {
        self::assertSame($httpOnly, $cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame($path, $cookie->getPath());
        self::assertNull($cookie->getDomain());
        self::assertGreaterThan(time() + 29 * 24 * 60 * 60, $cookie->getExpiresTime());
        self::assertLessThanOrEqual(time() + 30 * 24 * 60 * 60 + 60, $cookie->getExpiresTime());
    }

    private function assertNoCookiesOrSessions(): void
    {
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame(0, $this->countSessions());
    }

    private function assertRateLimitError(): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $error = $this->error($this->jsonBody());
        self::assertSame('RATE_LIMIT_EXCEEDED', $error['code']);
        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertMatchesRegularExpression('/\A\d+\z/', $retryAfter);
        self::assertGreaterThanOrEqual(1, (int) $retryAfter);
        self::assertLessThanOrEqual(900, (int) $retryAfter);
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
        $this->setEnv('EQUO_CSRF_SIGNING_KEY_VERSION', 'v1');
        $this->setEnv('EQUO_CSRF_SIGNING_KEY_RING', '{"v1":"Y3NyZi10b2tlbi1oYW1jLWRldi12MS1rZXktMzItYnl0ZXMh"}');
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
