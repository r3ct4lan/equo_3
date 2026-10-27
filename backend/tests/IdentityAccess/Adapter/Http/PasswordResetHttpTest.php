<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class PasswordResetHttpTest extends WebTestCase
{
    private const string USER = '8a000000-0000-4000-8000-000000000001';
    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
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

    public function testUnknownActiveAndInactiveRequestHaveIdenticalPublicContracts(): void
    {
        $signatures = [];
        foreach (['unknown', 'active', 'inactive'] as $case) {
            if ('active' === $case) {
                $this->user(true);
            } elseif ('inactive' === $case) {
                $this->db->executeStatement('UPDATE app_user SET is_active = false');
            }
            $this->post('/password-reset-requests', ['email' => 'unknown' === $case ? 'unknown@example.test' : '  Recovery@Example.Test  ']);
            self::assertResponseStatusCodeSame(202);
            self::assertSame(['status' => 'password_reset_email_scheduled'], $this->body());
            $response = $this->client->getResponse();
            $signatures[] = [$response->getStatusCode(), $response->getContent(), $response->headers->get('Content-Type'), $response->headers->get('Retry-After'), $response->headers->get('WWW-Authenticate'), $response->headers->getCookies(), $response->headers->has('X-Request-Id')];
        }
        self::assertSame($signatures[0], $signatures[1]);
        self::assertSame($signatures[0], $signatures[2]);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM email_delivery_outbox'));
    }

    public function testResetSuccessIsEmpty204ClearsExactCookiesAndDoesNotActivate(): void
    {
        $this->user(false);
        $this->post('/password-reset-requests', ['email' => 'recovery@example.test']);
        $token = $this->capability();
        $password = '  a1😀d  ';
        $this->post('/password-reset', ['token' => $token, 'newPassword' => $password]);
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
        self::assertFalse($this->db->fetchOne('SELECT is_active FROM app_user'));
        self::assertTrue(self::getContainer()->get(PasswordHashingPort::class)->verify($password, $this->db->fetchOne('SELECT password_hash FROM app_user')));
        $this->post('/password-reset', ['token' => $token, 'newPassword' => 'Another password1!']);
        $this->failure(410, 'TOKEN_USED');
    }

    #[DataProvider('tokenProvider')]
    public function testTokenLifecycleAndPurposeAreRecheckedWithoutWrites(string $state, int $status, string $code): void
    {
        $this->user(true);
        $issued = self::getContainer()->get(ActionTokenCodecPort::class)->issue();
        $now = self::getContainer()->get(ClockPort::class)->now();
        if ('unknown' !== $state && 'malformed' !== $state) {
            $this->db->insert('user_action_token', ['id' => '8a000000-0000-4000-8001-000000000001', 'user_id' => self::USER, 'token_hash' => $issued->tokenHash, 'purpose' => 'purpose' === $state ? 'ACTIVATE_ACCOUNT' : 'RESET_PASSWORD', 'created_at' => $now->modify('-1 hour')->format('Y-m-d H:i:sP'), 'expires_at' => $now->modify('expired' === $state ? '-1 second' : '+1 hour')->format('Y-m-d H:i:sP'), 'used_at' => 'used' === $state ? $now->format('Y-m-d H:i:sP') : null, 'invalidated_at' => 'invalidated' === $state ? $now->format('Y-m-d H:i:sP') : null]);
        }
        $this->post('/password-reset', ['token' => 'malformed' === $state ? 'invalid' : $issued->publicToken, 'newPassword' => 'New password1!']);
        $this->failure($status, $code);
        self::assertSame('original-hash', $this->db->fetchOne('SELECT password_hash FROM app_user'));
        self::assertStringNotContainsString($issued->publicToken, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('New password1!', (string) $this->client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function tokenProvider(): iterable
    {
        foreach (['unknown', 'malformed', 'purpose'] as $state) {
            yield $state => [$state, 400, 'INVALID_TOKEN'];
        }
        foreach (['expired', 'used', 'invalidated'] as $state) {
            yield $state => [$state, 410, 'TOKEN_'.strtoupper($state)];
        }
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('validationProvider')]
    public function testPasswordPolicyAndPayloadValidationExposeSafeFieldErrors(array $payload, string $field, string $code): void
    {
        $this->post('/password-reset', $payload);
        $this->failure(422, 'VALIDATION_ERROR');
        $violations = $this->body()['error']['details']['violations'];
        self::assertContains($field, array_column($violations, 'field'));
        self::assertContains($code, array_column($violations, 'code'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token'));
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function validationProvider(): iterable
    {
        yield 'missing' => [[], 'token', 'REQUIRED'];
        yield 'short' => [['token' => 'x', 'newPassword' => str_repeat('😀', 7)], 'newPassword', 'PASSWORD_POLICY_VIOLATION'];
        yield 'space' => [['token' => 'x', 'newPassword' => str_repeat(' ', 8)], 'newPassword', 'PASSWORD_POLICY_VIOLATION'];
        yield 'unicode too long' => [['token' => 'x', 'newPassword' => str_repeat('😀', 129)], 'newPassword', 'PASSWORD_POLICY_VIOLATION'];
        yield 'digits only' => [['token' => 'x', 'newPassword' => '12345678'], 'newPassword', 'PASSWORD_POLICY_VIOLATION'];
        yield 'no letter' => [['token' => 'x', 'newPassword' => '1234567!'], 'newPassword', 'PASSWORD_POLICY_VIOLATION'];
        yield 'no digit' => [['token' => 'x', 'newPassword' => 'Password!'], 'newPassword', 'PASSWORD_POLICY_VIOLATION'];
        yield 'space is not special' => [['token' => 'x', 'newPassword' => 'Password1 '], 'newPassword', 'PASSWORD_POLICY_VIOLATION'];
        yield 'wrong type' => [['token' => 'x', 'newPassword' => 123], 'newPassword', 'INVALID_TYPE'];
    }

    public function testRequestEmailQuotaNormalizesAndStopsFourthWithoutWrites(): void
    {
        foreach (['user@example.test', ' USER@Example.Test ', 'User@example.test'] as $email) {
            $this->post('/password-reset-requests', ['email' => $email]);
            self::assertResponseStatusCodeSame(202);
        }
        $this->post('/password-reset-requests', ['email' => 'user@example.test']);
        $this->rateFailure(3600);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token'));
    }

    public function testRequestIpQuotaIgnoresUntrustedForwardedAddress(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->post('/password-reset-requests', ['email' => 'unknown-'.$i.'@example.test'], ['HTTP_X_FORWARDED_FOR' => '198.51.100.'.$i]);
            self::assertResponseStatusCodeSame(202);
        }
        $this->post('/password-reset-requests', ['email' => 'next@example.test']);
        $this->rateFailure(3600);
    }

    public function testApplyQuotaIsSharedWithActivationAndUsesMalformedFingerprint(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->post(0 === $i % 2 ? '/activate' : '/password-reset', 0 === $i % 2 ? ['token' => 'invalid'] : ['token' => 'invalid', 'newPassword' => 'New password1!']);
            self::assertResponseStatusCodeSame(400);
        }
        $this->post('/password-reset', ['token' => 'invalid', 'newPassword' => 'New password1!']);
        $this->rateFailure(900);
    }

    public function testApplyIpQuotaIsSharedAcrossDistinctTokensAndPurposes(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->post(0 === $i % 2 ? '/activate' : '/password-reset', 0 === $i % 2 ? ['token' => 'invalid-'.$i] : ['token' => 'invalid-'.$i, 'newPassword' => 'New password1!']);
            self::assertResponseStatusCodeSame(400);
        }
        $this->post('/password-reset', ['token' => 'new-invalid', 'newPassword' => 'New password1!']);
        $this->rateFailure(900);
    }

    public function testRequestValidationAndJsonMediaTypeAreEnforced(): void
    {
        $this->post('/password-reset-requests', ['email' => 'invalid']);
        $this->failure(422, 'VALIDATION_ERROR');
        $this->client->request('POST', '/api/v1/auth/password-reset', server: ['CONTENT_TYPE' => 'text/plain'], content: 'invalid');
        self::assertResponseStatusCodeSame(415);
    }

    public function testResetSecretsAndPersistenceDetailsNeverReachPublicResponsesOrApiLogs(): void
    {
        $this->user(true);
        $logger = self::getContainer()->get('monolog.handler.api_test');
        $logger->clear();
        $this->post('/password-reset-requests', ['email' => 'recovery@example.test']);
        $token = $this->capability();
        $password = 'Private recovery password1!';
        $this->post('/password-reset', ['token' => $token, 'newPassword' => $password]);
        self::assertResponseStatusCodeSame(204);
        $storedHash = $this->db->fetchOne('SELECT password_hash FROM app_user');
        self::assertIsString($storedHash);
        $this->post('/password-reset', ['token' => $token, 'newPassword' => $password]);
        $this->failure(410, 'TOKEN_USED');
        $public = (string) $this->client->getResponse()->getContent();
        foreach ([$token, $password, $storedHash, 'user_action_token', 'UPDATE app_user', 'trace'] as $secret) {
            self::assertFalse(str_contains($public, $secret), 'The public response must not expose sensitive state.');
        }
        self::assertSame([], $logger->getRecords());
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $server
     */
    private function post(string $path, array $payload, array $server = []): void
    {
        $this->client->request('POST', '/api/v1/auth'.$path, server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.7', ...$server], content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function failure(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame($code, $this->body()['error']['code']);
        self::assertSame($this->client->getResponse()->headers->get('X-Request-Id'), $this->body()['error']['requestId']);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    private function rateFailure(int $maximum): void
    {
        $this->failure(429, 'RATE_LIMIT_EXCEEDED');
        $retry = (int) $this->client->getResponse()->headers->get('Retry-After');
        self::assertGreaterThan(0, $retry);
        self::assertLessThanOrEqual($maximum, $retry);
        self::assertArrayNotHasKey('details', $this->body()['error']);
    }

    private function user(bool $active): void
    {
        $this->db->insert('app_user', ['id' => self::USER, 'name' => 'Recovery User', 'email' => 'recovery@example.test', 'password_hash' => 'original-hash', 'is_active' => $active ? 1 : 0, 'created_at' => '2026-10-01 00:00:00+00']);
    }

    private function capability(): string
    {
        $ciphertext = $this->db->fetchOne('SELECT encrypted_payload FROM email_delivery_outbox');
        $ciphertext = is_resource($ciphertext) ? stream_get_contents($ciphertext) : $ciphertext;
        self::assertIsString($ciphertext);

        return self::getContainer()->get(PayloadCipher::class)->decrypt($ciphertext)['token'];
    }
}
