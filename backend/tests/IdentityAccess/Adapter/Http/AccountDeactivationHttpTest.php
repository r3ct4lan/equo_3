<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class AccountDeactivationHttpTest extends WebTestCase
{
    private const string USER = '8e000000-0000-4000-8000-000000000001';
    private const string PASSWORD = 'Correct password!';

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privatePem));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        foreach ([
            'EQUO_JWT_SIGNING_KEY_VERSION' => 'v1',
            'EQUO_JWT_SIGNING_PRIVATE_KEY' => base64_encode($privatePem),
            'EQUO_JWT_PUBLIC_KEY_RING' => json_encode(['v1' => base64_encode($details['key'])], JSON_THROW_ON_ERROR),
            'EQUO_JWT_ISSUER' => 'https://equo.test',
            'EQUO_JWT_AUDIENCE' => 'equo-api',
            'EQUO_JWT_ACCESS_TTL_SECONDS' => '900',
            'EQUO_JWT_CLOCK_SKEW_SECONDS' => '30',
            'EQUO_CSRF_SIGNING_KEY_VERSION' => 'v1',
            'EQUO_CSRF_SIGNING_KEY_RING' => '{"v1":"Y3NyZi1kZWFjdGl2YXRpb24tdGVzdC1rZXktMzItYnl0ZXMhIQ=="}',
            'EQUO_APPLICATION_ORIGIN' => 'https://equo.test',
        ] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        self::getContainer()->get('test.rate_limiter_cache')->clear();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testDeactivationIsAtomicRevokesAllSessionsAndClearsCookies(): void
    {
        $access = $this->fixture();

        $this->deactivate(self::PASSWORD, $access);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertFalse($this->client->getResponse()->headers->has('Content-Type'));
        self::assertFalse((bool) $this->db->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [self::USER]));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id = ? AND revoked_at IS NOT NULL', [self::USER]));

        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(2, $cookies);
        foreach ($cookies as $cookie) {
            self::assertContains($cookie->getName(), ['equo_refresh', '__Host-equo_csrf']);
            self::assertSame('equo_refresh' === $cookie->getName() ? '/api/v1/auth' : '/', $cookie->getPath());
            self::assertSame('equo_refresh' === $cookie->getName(), $cookie->isHttpOnly());
            self::assertTrue($cookie->isSecure());
            self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
            self::assertSame(0, $cookie->getMaxAge());
        }

        $this->client->request('GET', '/api/v1/connects', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$access]);
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
    }

    public function testWrongPasswordHasNoStateSessionOrCookieEffects(): void
    {
        $access = $this->fixture();
        $before = $this->db->fetchAllAssociative('SELECT * FROM user_session ORDER BY id');

        $this->deactivate('Wrong password!', $access);

        $this->failure(401, 'INVALID_CREDENTIALS');
        self::assertTrue((bool) $this->db->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [self::USER]));
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM user_session ORDER BY id'));
    }

    /** @param array<string, mixed>|null $payload */
    #[DataProvider('invalidRequests')]
    public function testInvalidOrUnauthenticatedRequestsDoNotDeactivate(?array $payload, ?string $access, int $status, string $code): void
    {
        $validAccess = $this->fixture();
        $this->client->request(
            'POST',
            '/api/v1/account/deactivate',
            server: ['CONTENT_TYPE' => 'application/json', ...($access ? ['HTTP_AUTHORIZATION' => 'Bearer '.('valid' === $access ? $validAccess : $access)] : [])],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $this->failure($status, $code);
        self::assertTrue((bool) $this->db->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [self::USER]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE revoked_at IS NOT NULL'));
    }

    /** @return iterable<string, array{?array<string, mixed>, ?string, int, string}> */
    public static function invalidRequests(): iterable
    {
        yield 'missing bearer' => [['currentPassword' => self::PASSWORD], null, 401, 'AUTHENTICATION_REQUIRED'];
        yield 'invalid bearer' => [['currentPassword' => self::PASSWORD], 'invalid', 401, 'AUTHENTICATION_REQUIRED'];
        yield 'missing password' => [[], 'valid', 422, 'VALIDATION_ERROR'];
        yield 'empty password' => [['currentPassword' => ''], 'valid', 422, 'VALIDATION_ERROR'];
        yield 'wrong type' => [['currentPassword' => 123], 'valid', 422, 'VALIDATION_ERROR'];
    }

    public function testDeactivatedAccountReactivatesThroughExistingFlowWithoutRestoringOldSessions(): void
    {
        $access = $this->fixture();
        $this->deactivate(self::PASSWORD, $access);
        self::assertResponseStatusCodeSame(204);

        $this->client->request(
            'POST',
            '/api/v1/auth/activation-requests',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.88'],
            content: json_encode(['email' => 'deactivate@example.test', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(202);

        $encrypted = $this->db->fetchOne('SELECT encrypted_payload FROM email_delivery_outbox ORDER BY created_at DESC LIMIT 1');
        if (is_resource($encrypted)) {
            $encrypted = stream_get_contents($encrypted);
        }
        self::assertIsString($encrypted);
        $token = self::getContainer()->get(PayloadCipher::class)->decrypt($encrypted)['token'] ?? null;
        self::assertIsString($token);

        $this->client->request(
            'POST',
            '/api/v1/auth/activate',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.89'],
            content: json_encode(['token' => $token], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertTrue((bool) $this->db->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [self::USER]));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id = ? AND revoked_at IS NOT NULL', [self::USER]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id = ? AND revoked_at IS NULL', [self::USER]));
    }

    private function fixture(): string
    {
        $this->db->insert('app_user', [
            'id' => self::USER,
            'name' => 'Deactivate User',
            'email' => 'deactivate@example.test',
            'password_hash' => self::getContainer()->get(PasswordHashingPort::class)->hash(self::PASSWORD),
            'is_active' => 1,
            'created_at' => '2026-10-01 00:00:00+00',
        ]);
        foreach ([1, 2] as $i) {
            $this->db->insert('user_session', [
                'id' => '8e000000-0000-4000-8001-'.sprintf('%012d', $i),
                'user_id' => self::USER,
                'refresh_token_hash' => 'sha256:'.hash('sha256', (string) $i),
                'created_at' => '2026-10-01 00:00:00+00',
                'expires_at' => '2099-11-01 00:00:00+00',
            ]);
        }

        return self::getContainer()->get(AccessTokenIssuerPort::class)->issue(self::USER)->accessToken;
    }

    private function deactivate(string $password, string $access): void
    {
        $this->client->request(
            'POST',
            '/api/v1/account/deactivate',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$access],
            content: json_encode(['currentPassword' => $password], JSON_THROW_ON_ERROR),
        );
    }

    private function failure(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($code, $body['error']['code']);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }
}
