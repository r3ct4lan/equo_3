<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256 as HmacSha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\RegisteredClaims;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Response;

final class MeHttpTest extends WebTestCase
{
    private const string ORIGIN = 'https://equo.test';
    private const string AUDIENCE = 'equo-api';
    private const string ISSUER = 'https://equo.test';
    private const string CSRF_KEY_RING = '{"v1":"bWUtY3NyZi1rZXktZm9yLWJyb3dzZXItdGVzdHMtMzIh"}';

    private KernelBrowser $client;
    private Connection $connection;
    private PasswordHashingPort $passwordHasher;

    /** @var array{private: string, public: string} */
    private array $keys;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->keys = self::rsaKeyPair();
        $this->configureEnvironment();

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

    public function testMeReturnsFreshCurrentUserWithoutCookiesOrSessionRotation(): void
    {
        $userId = '87000000-0000-4000-8000-000000000301';
        $this->insertUser($userId, 'Me User', 'me@example.test', true, 'Correct password!', '2026-07-26 18:42:15+00');
        $login = $this->login('me@example.test');
        $oldHash = $this->storedHash();
        $oldSessionCount = $this->countSessions();

        $body = $this->me($login['accessToken']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHasHeader('X-Request-Id');
        self::assertSame(['createdAt', 'email', 'id', 'isActive', 'name'], $this->sortedKeys($body));
        self::assertSame($userId, $body['id']);
        self::assertSame('Me User', $body['name']);
        self::assertSame('me@example.test', $body['email']);
        self::assertTrue($body['isActive']);
        self::assertSame('2026-07-26T18:42:15Z', $body['createdAt']);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame($oldHash, $this->storedHash());
        self::assertSame($oldSessionCount, $this->countSessions());
        self::assertStringNotContainsString('accessToken', json_encode($body, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('password', json_encode($body, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('roles', json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testMeReadsFreshDatabaseStateAndRejectsInactiveUserWithSameJwt(): void
    {
        $userId = '87000000-0000-4000-8000-000000000302';
        $this->insertUser($userId, 'Old Name', 'old@example.test', true, 'Correct password!');
        $login = $this->login('old@example.test');

        $this->connection->executeStatement('UPDATE app_user SET name = ?, email = ? WHERE id = ?', [
            'New Name',
            'new@example.test',
            $userId,
        ]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $fresh = $this->me($login['accessToken']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('New Name', $fresh['name']);
        self::assertSame('new@example.test', $fresh['email']);

        $this->connection->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$userId]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $inactive = $this->me($login['accessToken']);
        $this->assertAuthenticationRequired($inactive);
        self::assertArrayNotHasKey('name', $inactive);
    }

    #[DataProvider('invalidAuthorizationProvider')]
    public function testMeAuthenticationFailuresUseOneSafe401(?string $authorization): void
    {
        $body = $this->meWithAuthorization($authorization);

        $this->assertAuthenticationRequired($body);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertStringNotContainsString('jwt', strtolower((string) $this->client->getResponse()->getContent()));
        self::assertStringNotContainsString('signature', strtolower((string) $this->client->getResponse()->getContent()));
    }

    /** @return iterable<string, array{string|null}> */
    public static function invalidAuthorizationProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'basic' => ['Basic abc'];
        yield 'empty bearer' => ['Bearer'];
        yield 'blank bearer' => ['Bearer '];
        yield 'malformed jwt' => ['Bearer not-a-jwt'];
    }

    #[DataProvider('invalidJwtProvider')]
    public function testMeRejectsInvalidJwtVariants(string $case): void
    {
        $body = $this->me($this->invalidJwt($case));

        $this->assertAuthenticationRequired($body);
        self::assertStringNotContainsString($case, (string) $this->client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidJwtProvider(): iterable
    {
        yield 'invalid signature' => ['invalid-signature'];
        yield 'expired' => ['expired'];
        yield 'unknown kid' => ['unknown-kid'];
        yield 'wrong issuer' => ['wrong-issuer'];
        yield 'wrong audience' => ['wrong-audience'];
        yield 'wrong type' => ['wrong-type'];
        yield 'wrong algorithm' => ['wrong-algorithm'];
        yield 'missing claim' => ['missing-claim'];
        yield 'invalid subject' => ['invalid-subject'];
    }

    public function testMeRejectsValidTokensForUnknownAndInactiveUsers(): void
    {
        $unknown = self::getContainer()->get(AccessTokenIssuerPort::class)->issue('87000000-0000-4000-8000-000000000399')->accessToken;
        $this->assertAuthenticationRequired($this->me($unknown));

        $inactiveId = '87000000-0000-4000-8000-000000000303';
        $this->insertUser($inactiveId, 'Inactive User', 'inactive-me@example.test', false, 'Correct password!');
        $inactive = self::getContainer()->get(AccessTokenIssuerPort::class)->issue($inactiveId)->accessToken;
        $this->assertAuthenticationRequired($this->me($inactive));
    }

    public function testOnlyAuthorizationBearerAuthenticatesMe(): void
    {
        $userId = '87000000-0000-4000-8000-000000000304';
        $this->insertUser($userId, 'Cookie User', 'cookie-me@example.test', true, 'Correct password!');
        $login = $this->login('cookie-me@example.test');
        $cookies = $this->responseCookies();

        $this->client->getCookieJar()->set(new BrowserKitCookie('equo_refresh', $cookies['equo_refresh'], null, '/api/v1/auth', 'localhost', false));
        $this->client->getCookieJar()->set(new BrowserKitCookie('__Host-equo_csrf', $cookies['__Host-equo_csrf'], null, '/', 'localhost', false));
        $this->assertAuthenticationRequired($this->meWithAuthorization(null));

        $this->client->getCookieJar()->set(new BrowserKitCookie('accessToken', $login['accessToken'], null, '/', 'localhost', false));
        $this->assertAuthenticationRequired($this->meWithAuthorization(null));

        $this->client->request('GET', '/api/v1/me?accessToken='.rawurlencode($login['accessToken']));
        $this->assertAuthenticationRequired($this->jsonBody());

        $ok = $this->me($login['accessToken']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($userId, $ok['id']);
    }

    public function testPublicEndpointsRemainPublicAndRefreshKeepsItsOwnGuard(): void
    {
        $this->client->request('GET', '/api/health');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->request('POST', '/api/v1/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/v1/auth/activate', server: ['CONTENT_TYPE' => 'application/json'], content: '{"token":"invalid"}');
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $this->insertUser('87000000-0000-4000-8000-000000000305', 'Refresh User', 'refresh-public@example.test', true, 'Correct password!');
        $this->login('refresh-public@example.test');
        $cookies = $this->responseCookies();
        $this->client->getCookieJar()->set(new BrowserKitCookie('equo_refresh', $cookies['equo_refresh'], null, '/api/v1/auth', 'localhost', false));
        $this->client->getCookieJar()->set(new BrowserKitCookie('__Host-equo_csrf', $cookies['__Host-equo_csrf'], null, '/', 'localhost', false));
        $this->client->request('POST', '/api/v1/auth/refresh', server: [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_X_CSRF_TOKEN' => $cookies['__Host-equo_csrf'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    /** @return array<string, mixed> */
    private function login(string $email): array
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.30'],
            content: json_encode(['email' => $email, 'password' => 'Correct password!'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $this->jsonBody();
    }

    /** @return array<string, mixed> */
    private function me(string $accessToken): array
    {
        return $this->meWithAuthorization('Bearer '.$accessToken);
    }

    /** @return array<string, mixed> */
    private function meWithAuthorization(?string $authorization): array
    {
        $server = [];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        $this->client->request('GET', '/api/v1/me', server: $server);

        return $this->jsonBody();
    }

    /** @param array<string, mixed> $body */
    private function assertAuthenticationRequired(array $body): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHasHeader('X-Request-Id');
        self::assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertArrayHasKey('error', $body);
        self::assertSame([
            'code' => 'AUTHENTICATION_REQUIRED',
            'message' => 'Authentication is required.',
            'requestId' => $this->client->getResponse()->headers->get('X-Request-Id'),
        ], $body['error']);
        self::assertArrayNotHasKey('id', $body);
        self::assertStringNotContainsString('password', (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, string> */
    private function responseCookies(): array
    {
        $cookies = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $value = $cookie->getValue();
            self::assertIsString($value);
            $cookies[$cookie->getName()] = $value;
        }

        return $cookies;
    }

    private function insertUser(string $id, string $name, string $email, bool $active, string $password, string $createdAt = '2026-08-08 12:00:00+00'): void
    {
        $this->connection->insert('app_user', [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'password_hash' => $this->passwordHasher->hash($password),
            'is_active' => $active ? 1 : 0,
            'created_at' => $createdAt,
        ]);
    }

    private function storedHash(): string
    {
        $hash = $this->connection->fetchOne('SELECT refresh_token_hash FROM user_session');
        self::assertIsString($hash);

        return $hash;
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

    private function invalidJwt(string $case): string
    {
        $otherKeys = self::rsaKeyPair();

        return match ($case) {
            'invalid-signature' => $this->buildToken(privateKey: $otherKeys['private']),
            'expired' => $this->buildToken(issuedAt: new DateTimeImmutable('-1 hour', new DateTimeZone('UTC'))),
            'unknown-kid' => $this->buildToken(headers: ['kid' => 'v2']),
            'wrong-issuer' => $this->buildToken(issuer: 'https://issuer.invalid'),
            'wrong-audience' => $this->buildToken(audience: 'other-api'),
            'wrong-type' => $this->buildToken(headers: ['typ' => 'JWT']),
            'wrong-algorithm' => $this->buildHmacToken(),
            'missing-claim' => $this->buildToken(omit: [RegisteredClaims::SUBJECT]),
            'invalid-subject' => $this->buildToken(subject: 'not-a-uuid'),
            default => self::fail('Unknown invalid JWT case.'),
        };
    }

    /**
     * @param array<string, string> $headers
     * @param list<string>          $omit
     */
    private function buildToken(
        array $headers = [],
        array $omit = [],
        string $issuer = self::ISSUER,
        string $audience = self::AUDIENCE,
        string $subject = '87000000-0000-4000-8000-000000000398',
        ?DateTimeImmutable $issuedAt = null,
        ?string $privateKey = null,
    ): string {
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText(self::nonEmpty($privateKey ?? $this->keys['private'])),
            InMemory::plainText(self::nonEmpty($this->keys['public'])),
        );

        $issuedAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ('' === $issuer || '' === $audience || '' === $subject) {
            self::fail('JWT test claim values must be non-empty.');
        }
        /** @var non-empty-string $issuer */
        /** @var non-empty-string $audience */
        /** @var non-empty-string $subject */
        $builder = $configuration->builder()
            ->withHeader('typ', $headers['typ'] ?? 'at+jwt')
            ->withHeader('kid', $headers['kid'] ?? 'v1');

        if (!in_array(RegisteredClaims::ISSUER, $omit, true)) {
            $builder = $builder->issuedBy($issuer);
        }
        if (!in_array(RegisteredClaims::AUDIENCE, $omit, true)) {
            $builder = $builder->permittedFor($audience);
        }
        if (!in_array(RegisteredClaims::SUBJECT, $omit, true)) {
            $builder = $builder->relatedTo($subject);
        }
        if (!in_array(RegisteredClaims::ISSUED_AT, $omit, true)) {
            $builder = $builder->issuedAt($issuedAt);
        }
        if (!in_array(RegisteredClaims::EXPIRATION_TIME, $omit, true)) {
            $builder = $builder->expiresAt($issuedAt->modify('+900 seconds'));
        }
        if (!in_array(RegisteredClaims::ID, $omit, true)) {
            $builder = $builder->identifiedBy('jti-me-test');
        }

        return $builder->getToken($configuration->signer(), $configuration->signingKey())->toString();
    }

    private function buildHmacToken(): string
    {
        $configuration = Configuration::forSymmetricSigner(
            new HmacSha256(),
            InMemory::plainText(str_repeat('a', 32)),
        );

        return $configuration->builder()
            ->withHeader('typ', 'at+jwt')
            ->withHeader('kid', 'v1')
            ->issuedBy(self::ISSUER)
            ->permittedFor(self::AUDIENCE)
            ->relatedTo('87000000-0000-4000-8000-000000000397')
            ->issuedAt(new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->expiresAt(new DateTimeImmutable('+900 seconds', new DateTimeZone('UTC')))
            ->identifiedBy('jti-hmac')
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
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

    /** @return non-empty-string */
    private static function nonEmpty(string $value): string
    {
        if ('' === $value) {
            self::fail('Expected non-empty string.');
        }

        return $value;
    }

    private function configureEnvironment(): void
    {
        $this->setEnv('EQUO_JWT_SIGNING_KEY_VERSION', 'v1');
        $this->setEnv('EQUO_JWT_SIGNING_PRIVATE_KEY', base64_encode($this->keys['private']));
        $this->setEnv('EQUO_JWT_PUBLIC_KEY_RING', json_encode(['v1' => base64_encode($this->keys['public'])], JSON_THROW_ON_ERROR));
        $this->setEnv('EQUO_JWT_ISSUER', self::ISSUER);
        $this->setEnv('EQUO_JWT_AUDIENCE', self::AUDIENCE);
        $this->setEnv('EQUO_JWT_ACCESS_TTL_SECONDS', '900');
        $this->setEnv('EQUO_JWT_CLOCK_SKEW_SECONDS', '30');
        $this->setEnv('EQUO_CSRF_SIGNING_KEY_VERSION', 'v1');
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
