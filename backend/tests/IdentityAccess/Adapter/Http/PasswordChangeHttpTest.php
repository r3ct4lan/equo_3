<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class PasswordChangeHttpTest extends WebTestCase
{
    private const string USER = '8d000000-0000-4000-8000-000000000001';
    private KernelBrowser $client;
    private Connection $db;
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
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testSuccessClearsExactCookiesUsesSubjectAndLeavesJwtValid(): void
    {
        $access = $this->fixture();
        $password = '  ab😀d  ';
        $this->post(['currentPassword' => 'Correct password!', 'newPassword' => $password], $access);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertFalse($this->client->getResponse()->headers->has('Content-Type'));
        self::assertResponseHasHeader('X-Request-Id');
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
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE revoked_at IS NOT NULL'));
        $hash = $this->db->fetchOne('SELECT password_hash FROM app_user WHERE id = ?', [self::USER]);
        self::assertIsString($hash);
        self::assertTrue(self::getContainer()->get(PasswordHashingPort::class)->verify($password, $hash));
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$access]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testWrongCurrentPasswordIsSafeAndHasNoCookieOrPersistenceEffects(): void
    {
        $access = $this->fixture();
        $before = $this->db->fetchAllAssociative('SELECT * FROM user_session ORDER BY id');
        $hash = $this->db->fetchOne('SELECT password_hash FROM app_user');
        $logger = self::getContainer()->get('monolog.handler.api_test');
        $logger->clear();
        $this->post(['currentPassword' => 'Private wrong password!', 'newPassword' => 'Private new password!'], $access);
        $this->failure(401, 'INVALID_CREDENTIALS');
        self::assertFalse($this->client->getResponse()->headers->has('WWW-Authenticate'));
        $public = (string) $this->client->getResponse()->getContent();
        foreach (['Private wrong password!', 'Private new password!', (string) $hash, $access, 'UPDATE app_user', 'trace'] as $secret) {
            self::assertFalse(str_contains($public, $secret), 'Public errors must not expose sensitive material.');
        }
        self::assertSame($hash, $this->db->fetchOne('SELECT password_hash FROM app_user'));
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM user_session ORDER BY id'));
        self::assertSame([], $logger->getRecords());
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('validationCases')]
    public function testPayloadValidationDoesNotWriteOrClearCookies(array $payload, string $field, string $code): void
    {
        $access = $this->fixture();
        $this->post($payload, $access);
        $this->failure(422, 'VALIDATION_ERROR');
        $errors = $this->body()['error']['details']['violations'];
        self::assertContains($field, array_column($errors, 'field'));
        self::assertContains($code, array_column($errors, 'code'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE revoked_at IS NOT NULL'));
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function validationCases(): iterable
    {
        yield 'missing' => [[], 'currentPassword', 'REQUIRED'];
        yield 'empty current' => [['currentPassword' => '', 'newPassword' => 'New password!'], 'currentPassword', 'REQUIRED'];
        yield 'short' => [['currentPassword' => 'old', 'newPassword' => str_repeat('😀', 7)], 'newPassword', 'PASSWORD_POLICY_VIOLATION'];
        yield 'spaces' => [['currentPassword' => 'old', 'newPassword' => str_repeat(' ', 8)], 'newPassword', 'PASSWORD_POLICY_VIOLATION'];
        yield 'unicode long' => [['currentPassword' => 'old', 'newPassword' => str_repeat('😀', 129)], 'newPassword', 'PASSWORD_POLICY_VIOLATION'];
        yield 'wrong current type' => [['currentPassword' => 123, 'newPassword' => 'New password!'], 'currentPassword', 'INVALID_TYPE'];
        yield 'wrong new type' => [['currentPassword' => 'old', 'newPassword' => []], 'newPassword', 'INVALID_TYPE'];
    }

    #[DataProvider('bearerCases')]
    public function testAuthenticationRequiresValidBearerAndActiveSubject(string $case): void
    {
        $access = $this->fixture();
        if ('inactive' === $case) {
            $this->db->executeStatement('UPDATE app_user SET is_active = false');
        }
        if ('unknown' === $case) {
            $access = self::getContainer()->get(AccessTokenIssuerPort::class)->issue('8d000000-0000-4000-8000-000000000099')->accessToken;
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
        $this->post(['currentPassword' => 'Correct password!', 'newPassword' => 'New password!'], match ($case) {
            'missing' => null, 'invalid' => 'invalid', default => $access,
        });
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE revoked_at IS NOT NULL'));
    }

    /** @return iterable<string, array{string}> */
    public static function bearerCases(): iterable
    {
        foreach (['missing', 'invalid', 'expired', 'unknown', 'inactive'] as $case) {
            yield $case => [$case];
        }
    }

    public function testJsonMediaAndUnknownSubjectFieldAreRejected(): void
    {
        $access = $this->fixture();
        $this->client->request('POST', '/api/v1/account/password-change', server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer '.$access], content: 'not json');
        $this->failure(415, 'UNSUPPORTED_MEDIA_TYPE');
        $this->client->request('POST', '/api/v1/account/password-change', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$access], content: '{');
        $this->failure(400, 'INVALID_JSON');
        $this->post(['currentPassword' => 'Correct password!', 'newPassword' => 'New password!', 'userId' => 'foreign'], $access);
        $this->failure(400, 'INVALID_REQUEST');
    }

    private function fixture(): string
    {
        $this->db->insert('app_user', ['id' => self::USER, 'name' => 'Security', 'email' => 'security@example.test', 'password_hash' => self::getContainer()->get(PasswordHashingPort::class)->hash('Correct password!'), 'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00']);
        foreach ([1, 2] as $i) {
            $this->db->insert('user_session', ['id' => '8d000000-0000-4000-8001-'.sprintf('%012d', $i), 'user_id' => self::USER, 'refresh_token_hash' => 'sha256:'.hash('sha256', (string) $i), 'created_at' => '2026-10-01 00:00:00+00', 'expires_at' => '2099-11-01 00:00:00+00']);
        }

        return self::getContainer()->get(AccessTokenIssuerPort::class)->issue(self::USER)->accessToken;
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload, ?string $access): void
    {
        $this->client->request('POST', '/api/v1/account/password-change', server: ['CONTENT_TYPE' => 'application/json', ...($access ? ['HTTP_AUTHORIZATION' => 'Bearer '.$access] : [])], content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function failure(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame($code, $this->body()['error']['code']);
        self::assertSame($this->client->getResponse()->headers->get('X-Request-Id'), $this->body()['error']['requestId']);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
