<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\PayloadCipherPort;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EmailChangeHttpTest extends WebTestCase
{
    private const string USER = '8e000000-0000-4000-8000-000000000001';
    private const string OTHER = '8e000000-0000-4000-8000-000000000002';
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
            'EQUO_JWT_SIGNING_KEY_VERSION' => 'v1', 'EQUO_JWT_SIGNING_PRIVATE_KEY' => base64_encode($privatePem),
            'EQUO_JWT_PUBLIC_KEY_RING' => json_encode(['v1' => base64_encode($details['key'])], JSON_THROW_ON_ERROR),
            'EQUO_JWT_ISSUER' => 'https://equo.test', 'EQUO_JWT_AUDIENCE' => 'equo-api',
            'EQUO_JWT_ACCESS_TTL_SECONDS' => '900', 'EQUO_JWT_CLOCK_SKEW_SECONDS' => '30',
        ] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        self::getContainer()->get('test.rate_limiter_cache')->clear();
        $this->user(self::USER, 'current@example.test');
        $this->user(self::OTHER, 'used@example.test');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testRequestStoresOnlyNormalizedServerPayloadAndSchedulesNewAddress(): void
    {
        $this->requestChange('  NEW@Example.Test  ');

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['status' => 'email_confirmation_scheduled'], $this->body());
        self::assertSame('current@example.test', $this->db->fetchOne('SELECT email FROM app_user WHERE id = ?', [self::USER]));
        $token = $this->db->fetchAssociative('SELECT purpose, payload, EXTRACT(EPOCH FROM expires_at - created_at) AS ttl FROM user_action_token');
        self::assertIsArray($token);
        self::assertSame('CHANGE_EMAIL', $token['purpose']);
        self::assertSame(['newEmail' => 'new@example.test'], json_decode((string) $token['payload'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('1800.000000', $token['ttl']);
        $outbox = $this->db->fetchAssociative('SELECT recipient_email, template_key, encrypted_payload FROM email_delivery_outbox');
        self::assertIsArray($outbox);
        self::assertSame('new@example.test', $outbox['recipient_email']);
        self::assertSame('change-email', $outbox['template_key']);
        self::assertStringNotContainsString('new@example.test', (string) $outbox['encrypted_payload']);
    }

    public function testConfirmationChangesEmailConsumesTokenAndKeepsSessions(): void
    {
        $this->session();
        $this->requestChange('new@example.test');
        $token = $this->capability();

        $this->confirm($token);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertSame('new@example.test', $this->db->fetchOne('SELECT email FROM app_user WHERE id = ?', [self::USER]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE used_at IS NOT NULL'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE revoked_at IS NOT NULL'));
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testConfirmationEmailCapturesAccountLanguage(): void
    {
        $this->db->executeStatement("UPDATE app_user SET locale = 'ru' WHERE id = ?", [self::USER]);
        $this->requestChange('new@example.test');
        self::assertResponseStatusCodeSame(202);
        $encrypted = $this->db->fetchOne('SELECT encrypted_payload FROM email_delivery_outbox');
        $encrypted = is_resource($encrypted) ? stream_get_contents($encrypted) : $encrypted;
        self::assertIsString($encrypted);
        self::assertSame('ru', self::getContainer()->get(PayloadCipherPort::class)->decrypt($encrypted)['locale']);
    }

    public function testConfirmationRechecksUniquenessAndLeavesTokenReusableAfterConflict(): void
    {
        $this->requestChange('free@example.test');
        $token = $this->capability();
        $this->db->executeStatement('UPDATE app_user SET email = ? WHERE id = ?', ['free@example.test', self::OTHER]);

        $this->confirm($token);

        $this->failure(409, 'EMAIL_ALREADY_IN_USE');
        self::assertSame('current@example.test', $this->db->fetchOne('SELECT email FROM app_user WHERE id = ?', [self::USER]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE used_at IS NOT NULL'));
    }

    public function testRequestRejectsWrongPasswordAndUsedAddressWithoutWriting(): void
    {
        $access = $this->access();
        $this->postRequest(['currentPassword' => 'wrong', 'newEmail' => 'free@example.test'], $access);
        $this->failure(401, 'INVALID_CREDENTIALS');
        $this->postRequest(['currentPassword' => 'Correct password!', 'newEmail' => ' USED@example.test '], $access);
        $this->failure(409, 'EMAIL_ALREADY_IN_USE');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token'));
    }

    public function testEndpointsEnforceAuthenticationValidationAndOneTimeTokenState(): void
    {
        $this->postRequest(['currentPassword' => 'Correct password!', 'newEmail' => 'new@example.test'], null);
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
        $this->postRequest(['currentPassword' => 'Correct password!', 'newEmail' => 'invalid'], $this->access());
        $this->failure(422, 'VALIDATION_ERROR');
        $this->confirm('invalid');
        $this->failure(400, 'INVALID_TOKEN');

        $this->requestChange('new@example.test');
        $token = $this->capability();
        $this->confirm($token);
        self::assertResponseStatusCodeSame(204);
        $this->confirm($token);
        $this->failure(410, 'TOKEN_USED');
    }

    public function testRequestQuotaDoesNotReplaceTheLastUsableConfirmationOrRevokeSessions(): void
    {
        $this->session();
        for ($i = 0; $i < 3; ++$i) {
            $this->requestChange('new'.$i.'@example.test');
            self::assertResponseStatusCodeSame(202);
        }
        $token = $this->capability();
        $before = $this->emailChangeState();

        $this->requestChange('blocked@example.test');

        $this->failure(429, 'RATE_LIMIT_EXCEEDED');
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame($before, $this->emailChangeState());
        $this->confirm($token);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('new2@example.test', $this->db->fetchOne('SELECT email FROM app_user WHERE id = ?', [self::USER]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE revoked_at IS NOT NULL'));
    }

    public function testConfirmationQuotaPreservesAnOtherwiseUsableTokenWithoutChangingEmail(): void
    {
        $this->session();
        $this->requestChange('new@example.test');
        $token = $this->capability();
        $this->db->executeStatement('UPDATE app_user SET email = ? WHERE id = ?', ['new@example.test', self::OTHER]);
        for ($i = 0; $i < 5; ++$i) {
            $this->confirm($token);
            $this->failure(409, 'EMAIL_ALREADY_IN_USE');
        }
        $this->db->executeStatement('UPDATE app_user SET email = ? WHERE id = ?', ['used@example.test', self::OTHER]);
        $before = $this->emailChangeState();

        $this->confirm($token);

        $this->failure(429, 'RATE_LIMIT_EXCEEDED');
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame($before, $this->emailChangeState());
        self::assertSame('current@example.test', $this->db->fetchOne('SELECT email FROM app_user WHERE id = ?', [self::USER]));
    }

    #[DataProvider('terminalTokens')]
    public function testExpiredOrInvalidatedConfirmationDoesNotChangeEmailOrSessions(string $field, string $code): void
    {
        $this->session();
        $this->requestChange('new@example.test');
        $token = $this->capability();
        if ('expires_at' === $field) {
            $this->db->executeStatement("UPDATE user_action_token SET created_at = CURRENT_TIMESTAMP - INTERVAL '1 hour', expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second'");
        } else {
            $this->db->executeStatement('UPDATE user_action_token SET invalidated_at = CURRENT_TIMESTAMP');
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $before = $this->emailChangeState();

        $this->confirm($token);

        $this->failure(410, $code);
        self::assertSame($before, $this->emailChangeState());
        self::assertSame('current@example.test', $this->db->fetchOne('SELECT email FROM app_user WHERE id = ?', [self::USER]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function terminalTokens(): iterable
    {
        yield 'expired' => ['expires_at', 'TOKEN_EXPIRED'];
        yield 'invalidated' => ['invalidated_at', 'TOKEN_INVALIDATED'];
    }

    #[DataProvider('invalidTokenPayloads')]
    public function testMalformedStoredConfirmationPayloadFailsSafely(string $payload): void
    {
        $this->session();
        $this->requestChange('new@example.test');
        $token = $this->capability();
        $this->db->executeStatement('UPDATE user_action_token SET payload = ?', [$payload]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $before = $this->emailChangeState();

        $this->confirm($token);

        $this->failure(400, 'INVALID_TOKEN');
        self::assertSame($before, $this->emailChangeState());
        self::assertSame('current@example.test', $this->db->fetchOne('SELECT email FROM app_user WHERE id = ?', [self::USER]));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokenPayloads(): iterable
    {
        yield 'null payload' => ['null'];
        yield 'missing address' => ['{}'];
        yield 'wrong address type' => ['{"newEmail":42}'];
        yield 'malformed address' => ['{"newEmail":"private-invalid-address"}'];
    }

    public function testReplacementInvalidatesTheOldEmailConfirmationWithoutChangingAccountEmail(): void
    {
        $this->requestChange('old-request@example.test');
        $old = $this->capability();
        $this->requestChange('new-request@example.test');
        $new = $this->capability();
        self::assertNotSame($old, $new);

        $this->confirm($old);
        $this->failure(410, 'TOKEN_INVALIDATED');
        self::assertSame('current@example.test', $this->db->fetchOne('SELECT email FROM app_user WHERE id = ?', [self::USER]));
        $this->confirm($new);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('new-request@example.test', $this->db->fetchOne('SELECT email FROM app_user WHERE id = ?', [self::USER]));
    }

    /** @return array<string, mixed> */
    private function emailChangeState(): array
    {
        return [
            'tokens' => $this->db->fetchAllAssociative('SELECT id, payload, used_at, invalidated_at FROM user_action_token ORDER BY id'),
            'deliveries' => $this->db->fetchFirstColumn('SELECT id FROM email_delivery_outbox ORDER BY id'),
            'sessions' => $this->db->fetchAllAssociative('SELECT id, revoked_at FROM user_session ORDER BY id'),
        ];
    }

    private function requestChange(string $newEmail): void
    {
        $this->postRequest(['currentPassword' => 'Correct password!', 'newEmail' => $newEmail], $this->access());
    }

    /** @param array<string, mixed> $payload */
    private function postRequest(array $payload, ?string $access): void
    {
        $this->client->request('POST', '/api/v1/account/email-change-requests', server: [
            'CONTENT_TYPE' => 'application/json',
            ...($access ? ['HTTP_AUTHORIZATION' => 'Bearer '.$access] : []),
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function confirm(string $token): void
    {
        $this->client->request('POST', '/api/v1/auth/email-change-confirmations', server: [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.8',
        ], content: json_encode(['token' => $token], JSON_THROW_ON_ERROR));
    }

    private function access(): string
    {
        return self::getContainer()->get(AccessTokenIssuerPort::class)->issue(self::USER)->accessToken;
    }

    private function capability(): string
    {
        $encrypted = $this->db->fetchOne('SELECT outbox.encrypted_payload FROM email_delivery_outbox AS outbox INNER JOIN user_action_token AS token ON token.id = outbox.user_action_token_id WHERE token.invalidated_at IS NULL ORDER BY outbox.created_at DESC LIMIT 1');
        $encrypted = is_resource($encrypted) ? stream_get_contents($encrypted) : $encrypted;
        self::assertIsString($encrypted);

        return self::getContainer()->get(PayloadCipherPort::class)->decrypt($encrypted)['token'];
    }

    private function user(string $id, string $email): void
    {
        $this->db->insert('app_user', [
            'id' => $id, 'name' => 'Email User', 'email' => $email,
            'password_hash' => self::getContainer()->get(PasswordHashingPort::class)->hash('Correct password!'),
            'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00',
        ]);
    }

    private function session(): void
    {
        $this->db->insert('user_session', [
            'id' => '8e000000-0000-4000-8001-000000000001', 'user_id' => self::USER,
            'refresh_token_hash' => 'sha256:'.hash('sha256', 'session'),
            'created_at' => '2026-10-01 00:00:00+00', 'expires_at' => '2099-11-01 00:00:00+00',
        ]);
    }

    private function failure(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame($code, $this->body()['error']['code']);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
