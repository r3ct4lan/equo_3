<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserActionTokenRecord;
use App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;

final class FirstVerticalSliceHttpTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->connection->beginTransaction();
        $rateLimitCache = self::getContainer()->get('test.rate_limiter_cache');
        $rateLimitCache->clear();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testCompleteRegistrationAndActivationFlowMatchesContract(): void
    {
        $body = $this->register('flow@example.test', '11111111-1111-4111-8111-111111111111');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['activationRequired', 'user'], $this->sortedKeys($body));
        self::assertSame(['createdAt', 'email', 'id', 'isActive', 'name'], $this->sortedKeys($body['user']));
        self::assertSame('flow@example.test', $body['user']['email']);
        self::assertFalse($body['user']['isActive']);
        self::assertTrue($body['activationRequired']);
        self::assertTrue(Uuid::isValid($body['user']['id']));
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $body['user']['createdAt']);
        self::assertResponseHasHeader('X-Request-Id');
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        self::assertSame(1, $this->countRows('app_user'));
        self::assertSame(1, $this->countRows('user_action_token'));
        self::assertSame(1, $this->countRows('email_delivery_outbox'));
        self::assertSame(1, $this->countRows('idempotency_record'));
        self::assertNotContains('user_session', $this->connection->createSchemaManager()->listTableNames());

        $outbox = $this->entityManager->getRepository(EmailDeliveryOutboxRecord::class)->findOneBy([]);
        self::assertInstanceOf(EmailDeliveryOutboxRecord::class, $outbox);
        self::assertNotNull($outbox->encryptedPayload());
        $payload = self::getContainer()->get(PayloadCipher::class)->decrypt($outbox->encryptedPayload());
        $rawToken = $payload['token'];
        self::assertStringNotContainsString($rawToken, (string) $this->connection->fetchOne(
            'SELECT token_hash FROM user_action_token LIMIT 1',
        ));

        $this->jsonRequest('POST', '/api/v1/auth/activate', ['token' => $rawToken], ip: '192.0.2.2');

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->client->getResponse()->headers->get('Set-Cookie'));
        self::assertSame(true, $this->connection->fetchOne('SELECT is_active FROM app_user LIMIT 1'));
        self::assertNotFalse($this->connection->fetchOne('SELECT used_at FROM user_action_token LIMIT 1'));
    }

    public function testRegistrationNormalizesEmailAndNeverReturnsSensitiveFields(): void
    {
        $body = $this->register('  User.One+E1@Example.Test  ', '21111111-1111-4111-8111-111111111111');
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);

        self::assertSame('user.one+e1@example.test', $body['user']['email']);
        self::assertStringNotContainsString('password', strtolower($encoded));
        self::assertStringNotContainsString('token', strtolower($encoded));
        self::assertStringNotContainsString('payload', strtolower($encoded));
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidRegistrationProvider')]
    public function testInvalidRegistrationPayloadReturnsValidationError(array $payload, string $field, string $code): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $payload,
            '31111111-1111-4111-8111-111111111111',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $error = $this->error();
        self::assertSame('VALIDATION_ERROR', $error['code']);
        self::assertContains($field, array_column($error['details']['violations'], 'field'));
        self::assertContains($code, array_column($error['details']['violations'], 'code'));
        self::assertSame(0, $this->countRows('app_user'));
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidRegistrationProvider(): iterable
    {
        yield 'missing name' => [['email' => 'a@example.test', 'password' => 'A2345678901!'], 'name', 'REQUIRED'];
        yield 'blank name' => [['name' => '   ', 'email' => 'a@example.test', 'password' => 'A2345678901!'], 'name', 'REQUIRED'];
        yield 'missing email' => [['name' => 'Alex', 'password' => 'A2345678901!'], 'email', 'REQUIRED'];
        yield 'invalid email' => [['name' => 'Alex', 'email' => 'not-an-email', 'password' => 'A2345678901!'], 'email', 'INVALID_EMAIL'];
        yield 'missing password' => [['name' => 'Alex', 'email' => 'a@example.test'], 'password', 'REQUIRED'];
        yield 'wrong field type' => [['name' => [], 'email' => 'a@example.test', 'password' => 'A2345678901!'], 'name', 'INVALID_TYPE'];
    }

    public function testMalformedJsonIsSafeForBothEndpoints(): void
    {
        foreach (['/api/v1/auth/register', '/api/v1/auth/activate'] as $path) {
            $this->client->request(
                'POST',
                $path,
                server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => Uuid::v4()->toRfc4122()],
                content: '{"broken":',
            );
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
            self::assertSame('INVALID_JSON', $this->error()['code']);
        }

        self::assertSame(0, $this->countRows('app_user'));
    }

    public function testMissingIdempotencyKeyIsRejectedBeforeAnyWrite(): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration('missing-key@example.test'));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('IDEMPOTENCY_KEY_REQUIRED', $this->error()['code']);
        self::assertSame(0, $this->countRows('app_user'));
    }

    #[DataProvider('validPasswordBoundaryProvider')]
    public function testValidPasswordBoundariesReachPersistenceWithoutNormalization(
        string $password,
        string $email,
    ): void {
        $this->register($email, Uuid::v4()->toRfc4122(), password: $password);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $storedHash = $this->connection->fetchOne('SELECT password_hash FROM app_user WHERE email = ?', [$email]);
        self::assertIsString($storedHash);
        $hasher = self::getContainer()->get(PasswordHasherFactoryInterface::class)
            ->getPasswordHasher(PasswordHashingPort::class);
        self::assertTrue($hasher->verify($storedHash, $password));

        if ($password !== trim($password)) {
            self::assertFalse($hasher->verify($storedHash, trim($password)));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function validPasswordBoundaryProvider(): iterable
    {
        yield '12 code points' => [str_repeat('a', 12), 'password-min@example.test'];
        yield '128 code points' => [str_repeat('я', 128), 'password-max@example.test'];
        yield 'significant whitespace' => ['  abcdefghij  ', 'password-whitespace@example.test'];
    }

    #[DataProvider('invalidPasswordBoundaryProvider')]
    public function testPasswordPolicyViolationHasStableBusinessCode(string $password, string $email): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration($email, $password),
            Uuid::v4()->toRfc4122(),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('PASSWORD_POLICY_VIOLATION', $this->error()['code']);
        self::assertStringNotContainsString($password, (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->countRows('app_user'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPasswordBoundaryProvider(): iterable
    {
        yield '11 code points' => [str_repeat('a', 11), 'password-short@example.test'];
        yield '129 code points' => [str_repeat('a', 129), 'password-long@example.test'];
        yield 'whitespace only' => [str_repeat(' ', 12), 'password-blank@example.test'];
    }

    public function testUnknownRequestFieldsAreRejectedWithoutWrites(): void
    {
        $registration = $this->validRegistration('unknown-field@example.test');
        $registration['ownerId'] = '00000000-0000-4000-8000-000000000001';
        $this->jsonRequest('POST', '/api/v1/auth/register', $registration, Uuid::v4()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('INVALID_REQUEST', $this->error()['code']);
        self::assertSame(0, $this->countRows('app_user'));

        $this->jsonRequest('POST', '/api/v1/auth/activate', [
            'token' => self::getContainer()->get(ActionTokenCodecPort::class)->issue()->publicToken,
            'userId' => '00000000-0000-4000-8000-000000000001',
        ], ip: '192.0.2.49');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('INVALID_REQUEST', $this->error()['code']);
    }

    public function testNormalizedDuplicateEmailReturnsConflictWithoutSecondSet(): void
    {
        $this->register('user.one@example.test', '51111111-1111-4111-8111-111111111111');
        $this->register('  User.One@Example.Test  ', '51111111-1111-4111-8111-222222222222');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('EMAIL_ALREADY_EXISTS', $this->error()['code']);
        self::assertSame(1, $this->countRows('app_user'));
        self::assertSame(1, $this->countRows('user_action_token'));
        self::assertSame(1, $this->countRows('email_delivery_outbox'));
    }

    public function testIdempotentReplayReturnsOriginalResponseAndDifferentBodyConflicts(): void
    {
        $key = '61111111-1111-4111-8111-111111111111';
        $first = $this->register('replay@example.test', $key);
        $firstContent = (string) $this->client->getResponse()->getContent();
        $second = $this->register('replay@example.test', $key);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($first, $second);
        self::assertSame($firstContent, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->countRows('app_user'));
        self::assertSame(1, $this->countRows('email_delivery_outbox'));
        $storedFingerprint = $this->connection->fetchOne(
            'SELECT request_hash FROM idempotency_record WHERE idempotency_key = ?',
            [$key],
        );
        self::assertIsString($storedFingerprint);
        self::assertMatchesRegularExpression('/\Av1:[A-Za-z0-9_-]{43}\z/', $storedFingerprint);
        self::assertNotSame(hash('sha256', json_encode([
            'email' => 'replay@example.test',
            'name' => 'Alex Doe',
            'password' => 'A2345678901!',
        ], JSON_THROW_ON_ERROR)), $storedFingerprint);

        $changed = $this->validRegistration('replay@example.test');
        $changed['name'] = 'Changed Name';
        $this->jsonRequest('POST', '/api/v1/auth/register', $changed, $key, '192.0.2.61');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('IDEMPOTENCY_KEY_REUSED', $this->error()['code']);
    }

    public function testIdempotentReplayKeepsOriginalResultWithoutConsumingRegistrationRateLimits(): void
    {
        $key = '61211111-1111-4111-8111-111111111111';
        $first = $this->register('replay-rate-limit@example.test', $key, '192.0.2.60');

        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $replay = $this->register('replay-rate-limit@example.test', $key, '192.0.2.60');

            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            self::assertSame($first, $replay);
        }

        self::assertSame(1, $this->countRows('app_user'));
        self::assertSame(1, $this->countRows('user_action_token'));
        self::assertSame(1, $this->countRows('email_delivery_outbox'));
        self::assertSame(1, $this->countRows('idempotency_record'));
    }

    public function testExpiredIdempotencyWindowIsNotReplayed(): void
    {
        $key = '62111111-1111-4111-8111-111111111111';
        $this->register('expired-replay@example.test', $key, '192.0.2.62');
        $this->connection->executeStatement(
            "UPDATE idempotency_record SET created_at = CURRENT_TIMESTAMP - INTERVAL '25 hours', expires_at = CURRENT_TIMESTAMP - INTERVAL '1 hour' WHERE idempotency_key = ?",
            [$key],
        );
        $this->entityManager->clear();

        $this->register('expired-replay@example.test', $key, '192.0.2.62');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('EMAIL_ALREADY_EXISTS', $this->error()['code']);
        self::assertSame(1, $this->countRows('app_user'));
    }

    public function testUnknownAndMissingTokenDoNotChangeUsers(): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/activate', [], ip: '192.0.2.70');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('VALIDATION_ERROR', $this->error()['code']);

        $codec = self::getContainer()->get(ActionTokenCodecPort::class);
        $this->jsonRequest('POST', '/api/v1/auth/activate', ['token' => $codec->issue()->publicToken], ip: '192.0.2.71');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('INVALID_TOKEN', $this->error()['code']);
    }

    #[DataProvider('tokenLifecycleProvider')]
    public function testTokenLifecycleErrorsAreStable(
        UserActionTokenPurpose $purpose,
        string $state,
        int $expectedStatus,
        string $expectedCode,
    ): void {
        $rawToken = $this->tokenFixture($purpose, $state);
        $this->jsonRequest('POST', '/api/v1/auth/activate', ['token' => $rawToken], ip: '192.0.2.'.random_int(80, 180));

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame($expectedCode, $this->error()['code']);
        self::assertSame(false, $this->connection->fetchOne('SELECT is_active FROM app_user ORDER BY created_at DESC LIMIT 1'));
    }

    /** @return iterable<string, array{UserActionTokenPurpose, string, int, string}> */
    public static function tokenLifecycleProvider(): iterable
    {
        yield 'expired' => [UserActionTokenPurpose::ActivateAccount, 'expired', 410, 'TOKEN_EXPIRED'];
        yield 'used' => [UserActionTokenPurpose::ActivateAccount, 'used', 410, 'TOKEN_USED'];
        yield 'invalidated' => [UserActionTokenPurpose::ActivateAccount, 'invalidated', 410, 'TOKEN_INVALIDATED'];
        yield 'wrong purpose' => [UserActionTokenPurpose::ResetPassword, 'valid', 400, 'INVALID_TOKEN'];
    }

    public function testTokenCapabilityCannotActivateAnotherUserAndBearerHeaderDoesNotBypassIt(): void
    {
        $first = $this->registrationWithRawToken('first@example.test', '71111111-1111-4111-8111-111111111111');
        $second = $this->registrationWithRawToken('second@example.test', '71111111-1111-4111-8111-222222222222', '192.0.2.72');

        $this->jsonRequest(
            'POST',
            '/api/v1/auth/activate',
            ['token' => $first['token']],
            ip: '192.0.2.73',
            authorization: 'Bearer arbitrary-token',
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(true, $this->connection->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [$first['userId']]));
        self::assertSame(false, $this->connection->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [$second['userId']]));

        $this->jsonRequest(
            'POST',
            '/api/v1/auth/activate',
            ['token' => self::getContainer()->get(ActionTokenCodecPort::class)->issue()->publicToken],
            ip: '192.0.2.74',
            authorization: 'Bearer arbitrary-token',
        );
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('INVALID_TOKEN', $this->error()['code']);
    }

    public function testRegistrationIpRateLimitUsesFivePerHourBoundary(): void
    {
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->register(
                sprintf('ip-limit-%d@example.test', $attempt),
                Uuid::v4()->toRfc4122(),
                '198.51.100.1',
            );
        }

        $this->assertRateLimitError(3600);
        self::assertSame(5, $this->countRows('app_user'));
    }

    public function testRegistrationEmailRateLimitUsesNormalizedEmailAcrossIps(): void
    {
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->register(
                0 === $attempt % 2 ? '  Email-Limit@Example.Test  ' : 'email-limit@example.test',
                Uuid::v4()->toRfc4122(),
                '198.51.100.'.(10 + $attempt),
            );
        }

        $this->assertRateLimitError(86_400);
        self::assertSame(1, $this->countRows('app_user'));
    }

    public function testActivationIpRateLimitUsesTenPerFifteenMinutesBoundary(): void
    {
        $codec = self::getContainer()->get(ActionTokenCodecPort::class);

        for ($attempt = 1; $attempt <= 11; ++$attempt) {
            $this->jsonRequest(
                'POST',
                '/api/v1/auth/activate',
                ['token' => $codec->issue()->publicToken],
                ip: '198.51.100.30',
            );
        }

        $this->assertRateLimitError(900);
        self::assertSame(0, $this->countRows('app_user'));
    }

    public function testActivationTokenRateLimitUsesFivePerFifteenMinutesAcrossIps(): void
    {
        $rawToken = self::getContainer()->get(ActionTokenCodecPort::class)->issue()->publicToken;

        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->jsonRequest(
                'POST',
                '/api/v1/auth/activate',
                ['token' => $rawToken],
                ip: '198.51.100.'.(40 + $attempt),
            );
        }

        $this->assertRateLimitError(900);
        self::assertSame(0, $this->countRows('app_user'));
    }

    public function testHealthEndpointStillWorks(): void
    {
        $this->client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHasHeader('X-Request-Id');
    }

    /** @return array<string, mixed> */
    private function register(
        string $email,
        string $key,
        string $ip = '192.0.2.1',
        string $password = 'A2345678901!',
    ): array {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration($email, $password), $key, $ip);

        return $this->jsonBody();
    }

    /** @return array{name: string, email: string, password: string} */
    private function validRegistration(string $email, string $password = 'A2345678901!'): array
    {
        return ['name' => 'Alex Doe', 'email' => $email, 'password' => $password];
    }

    /** @param array<string, mixed> $payload */
    private function jsonRequest(
        string $method,
        string $path,
        array $payload,
        ?string $idempotencyKey = null,
        string $ip = '192.0.2.1',
        ?string $authorization = null,
    ): void {
        $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip];
        if (null !== $idempotencyKey) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        $this->client->request($method, $path, server: $server, content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function error(): array
    {
        $body = $this->jsonBody();
        self::assertArrayHasKey('error', $body);
        self::assertIsArray($body['error']);

        return $body['error'];
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function assertRateLimitError(int $maximumRetryAfter): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame('RATE_LIMIT_EXCEEDED', $this->error()['code']);
        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertMatchesRegularExpression('/\A\d+\z/', $retryAfter);
        self::assertGreaterThanOrEqual(1, (int) $retryAfter);
        self::assertLessThanOrEqual($maximumRetryAfter, (int) $retryAfter);
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

    private function tokenFixture(UserActionTokenPurpose $purpose, string $state): string
    {
        $now = new DateTimeImmutable('now');
        $codec = self::getContainer()->get(ActionTokenCodecPort::class);
        $issued = $codec->issue();
        $user = new UserRecord(
            Uuid::v7()->toRfc4122(),
            'Token Owner',
            Uuid::v7()->toRfc4122().'@example.test',
            'password-hash',
            false,
            $now,
        );
        $token = new UserActionTokenRecord(
            Uuid::v7()->toRfc4122(),
            $user,
            $issued->tokenHash,
            $purpose,
            null,
            $now->modify('-2 hours'),
            'expired' === $state ? $now->modify('-1 hour') : $now->modify('+1 hour'),
            'used' === $state ? $now->modify('-30 minutes') : null,
            'invalidated' === $state ? $now->modify('-30 minutes') : null,
        );
        $this->entityManager->persist($user);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $issued->publicToken;
    }

    /** @return array{userId: string, token: string} */
    private function registrationWithRawToken(string $email, string $key, string $ip = '192.0.2.71'): array
    {
        $body = $this->register($email, $key, $ip);
        $outbox = $this->entityManager->getRepository(EmailDeliveryOutboxRecord::class)->findOneBy(
            ['recipientEmail' => mb_strtolower(trim($email))],
        );
        self::assertInstanceOf(EmailDeliveryOutboxRecord::class, $outbox);
        self::assertNotNull($outbox->encryptedPayload());
        $payload = self::getContainer()->get(PayloadCipher::class)->decrypt($outbox->encryptedPayload());

        return ['userId' => $body['user']['id'], 'token' => $payload['token']];
    }
}
