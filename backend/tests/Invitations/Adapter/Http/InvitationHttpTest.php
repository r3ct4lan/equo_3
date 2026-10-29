<?php

declare(strict_types=1);

namespace App\Tests\Invitations\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class InvitationHttpTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    /** @var list<string> */ private array $users = [];
    /** @var list<string> */ private array $access = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        foreach (['EQUO_JWT_SIGNING_KEY_VERSION' => 'v1', 'EQUO_JWT_SIGNING_PRIVATE_KEY' => base64_encode($pem), 'EQUO_JWT_PUBLIC_KEY_RING' => json_encode(['v1' => base64_encode($details['key'])], JSON_THROW_ON_ERROR), 'EQUO_JWT_ISSUER' => 'https://equo.test', 'EQUO_JWT_AUDIENCE' => 'equo-api', 'EQUO_APPLICATION_ORIGIN' => 'https://equo.test'] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->client = self::createClient();
        $this->client->disableReboot();
        $c = self::getContainer();
        $c->get('test.rate_limiter_cache')->clear();
        $this->db = $c->get(Connection::class);
        $this->db->beginTransaction();
        for ($n = 0; $n < 3; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->users[] = $id;
            $this->db->insert('app_user', ['id' => $id, 'name' => 'HTTP person '.$n, 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00']);
            $this->access[] = $c->get(AccessTokenIssuerPort::class)->issue($id)->accessToken;
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }parent::tearDown();
    }

    public function testPublicPreviewProtectedAcceptanceAndBothReadModels(): void
    {
        $this->request('POST', '/api/v1/connect-invitations', $this->access[0]);
        self::assertResponseStatusCodeSame(200);
        $first = $this->body();
        self::assertTrue($first['created']);
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $token = $first['token'];
        self::assertIsString($token);
        self::assertSame('https://equo.test/connect-invitations/'.$token, $first['url']);
        $this->request('POST', '/api/v1/connect-invitations', $this->access[0]);
        self::assertFalse($this->body()['created']);
        self::assertTrue($token === $this->body()['token']);
        $this->request('GET', '/api/v1/connect-invitations/'.$token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['id' => $this->users[0], 'name' => 'HTTP person 0'], $this->body()['inviter']);
        $this->request('POST', '/api/v1/connect-invitations/'.$token.'/accept', $this->access[1]);
        self::assertResponseStatusCodeSame(201);
        $connect = $this->body();
        self::assertSame('created', $connect['invitationOutcome']);
        $id = $connect['id'];
        self::assertIsString($id);
        self::assertSame(0, $connect['balanceAmount']);
        self::assertArrayNotHasKey('email', $connect['otherUser']);
        $this->request('POST', '/api/v1/connect-invitations/'.$token.'/accept', $this->access[1]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($id, $this->body()['id']);
        self::assertSame('confirmed', $this->body()['invitationOutcome']);
        $this->request('POST', '/api/v1/connect-invitations', $this->access[0]);
        $nextToken = $this->body()['token'];
        self::assertIsString($nextToken);
        $this->request('POST', '/api/v1/connect-invitations/'.$nextToken.'/accept', $this->access[1]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($id, $this->body()['id']);
        self::assertSame('already_connected', $this->body()['invitationOutcome']);
        foreach ([0, 1] as $n) {
            $this->request('GET', '/api/v1/connects', $this->access[$n]);
            self::assertResponseStatusCodeSame(200);
            self::assertSame($id, $this->body()['items'][0]['id']);
            $this->request('GET', '/api/v1/connects/'.$id, $this->access[$n]);
            self::assertResponseStatusCodeSame(200);
            self::assertSame($this->users[1 - $n], $this->body()['otherUser']['id']);
        }
        $this->request('GET', '/api/v1/connects', $this->access[2]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->body()['items']);
        $this->request('GET', '/api/v1/connects/'.$id, $this->access[2]);
        $this->failure(404, 'RESOURCE_NOT_FOUND');
        $hiddenConnectError = $this->body()['error'];
        $this->request('GET', '/api/v1/connects/'.Uuid::v4()->toRfc4122(), $this->access[2]);
        $this->failure(404, 'RESOURCE_NOT_FOUND');
        self::assertSame($hiddenConnectError['code'], $this->body()['error']['code']);
        self::assertSame($hiddenConnectError['message'], $this->body()['error']['message']);
    }

    #[DataProvider('protectedPaths')]
    public function testBearerIsRequired(string $method, string $path): void
    {
        $this->request($method, $path);
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        $this->request($method, $path, 'invalid');
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
    }

    /** @return iterable<string, array{string,string}> */
    public static function protectedPaths(): iterable
    {
        yield 'create' => ['POST', '/api/v1/connect-invitations'];
        yield 'accept' => ['POST', '/api/v1/connect-invitations/invalid/accept'];
        yield 'pending accept' => ['POST', '/api/v1/connect-invitations/pending/accept'];
        yield 'list' => ['GET', '/api/v1/connects'];
        yield 'detail' => ['GET', '/api/v1/connects/invalid'];
    }

    public function testOwnInvitationHasSafeValidationError(): void
    {
        $this->request('POST', '/api/v1/connect-invitations', $this->access[0]);
        $token = $this->body()['token'];
        self::assertIsString($token);
        $this->request('POST', '/api/v1/connect-invitations/'.$token.'/accept', $this->access[0]);
        $this->failure(422, 'VALIDATION_ERROR');
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect_invitation WHERE created_by_id = ? AND used_at IS NOT NULL', [$this->users[0]]));
    }

    public function testInactiveAuthorIsConflictAndPreviewCannotAccept(): void
    {
        $this->request('POST', '/api/v1/connect-invitations', $this->access[0]);
        $token = $this->body()['token'];
        self::assertIsString($token);
        $this->db->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$this->users[0]]);
        $this->request('GET', '/api/v1/connect-invitations/'.$token);
        self::assertFalse($this->body()['canAccept']);
        $this->request('POST', '/api/v1/connect-invitations/'.$token.'/accept', $this->access[1]);
        $this->failure(409, 'INACTIVE_USER');
    }

    public function testInactiveAcceptorCannotConsumeInvitation(): void
    {
        $this->request('POST', '/api/v1/connect-invitations', $this->access[0]);
        self::assertResponseStatusCodeSame(200);
        $token = $this->body()['token'];
        self::assertIsString($token);
        $this->db->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$this->users[1]]);

        $this->request('POST', '/api/v1/connect-invitations/'.$token.'/accept', $this->access[1]);
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect WHERE first_user_id=? OR second_user_id=?', [$this->users[1], $this->users[1]]));
        self::assertNull($this->db->fetchOne('SELECT used_at FROM connect_invitation WHERE created_by_id=?', [$this->users[0]]));
    }

    public function testExpiredUsedAndInvalidPreviewCodes(): void
    {
        $this->request('GET', '/api/v1/connect-invitations/invalid');
        $this->failure(404, 'RESOURCE_NOT_FOUND');
        $this->request('POST', '/api/v1/connect-invitations', $this->access[0]);
        $token = $this->body()['token'];
        self::assertIsString($token);
        $this->request('POST', '/api/v1/connect-invitations/'.$token.'/accept', $this->access[1]);
        self::assertResponseStatusCodeSame(201);
        $this->request('GET', '/api/v1/connect-invitations/'.$token);
        $this->failure(410, 'TOKEN_USED');
        $this->request('POST', '/api/v1/connect-invitations', $this->access[0]);
        $expired = $this->body()['token'];
        self::assertIsString($expired);
        $this->db->executeStatement("UPDATE connect_invitation SET created_at=now()-interval '8 days',expires_at=now()-interval '1 day' WHERE created_by_id=? AND used_at IS NULL", [$this->users[0]]);
        $this->request('GET', '/api/v1/connect-invitations/'.$expired);
        $this->failure(410, 'TOKEN_EXPIRED');
    }

    public function testRateLimitReturnsRetryAfterAndDoesNotConsumeInvitation(): void
    {
        $this->request('POST', '/api/v1/connect-invitations', $this->access[0]);
        $token = $this->body()['token'];
        self::assertIsString($token);
        for ($n = 0; $n < 5; ++$n) {
            $this->request('POST', '/api/v1/connect-invitations/'.$token.'/accept', $this->access[0]);
            $this->failure(422, 'VALIDATION_ERROR');
        }
        $this->request('POST', '/api/v1/connect-invitations/'.$token.'/accept', $this->access[1]);
        $this->failure(429, 'RATE_LIMIT_EXCEEDED');
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect_invitation WHERE created_by_id = ? AND used_at IS NOT NULL', [$this->users[0]]));
    }

    #[DataProvider('invalidQuery')]
    public function testListRejectsMalformedPagination(string $query): void
    {
        $this->request('GET', '/api/v1/connects?'.$query, $this->access[0]);
        $this->failure(400, 'INVALID_REQUEST');
    }

    /** @return iterable<string,array{string}> */
    public static function invalidQuery(): iterable
    {
        yield 'zero' => ['limit=0'];
        yield 'too large' => ['limit=101'];
        yield 'array' => ['limit[]=20'];
        yield 'bad cursor' => ['cursor=invalid'];
        yield 'unexpected' => ['otherUserId=foo'];
        yield 'empty cursor' => ['cursor='];
        yield 'oversized cursor' => ['cursor='.str_repeat('a', 257)];
        yield 'invalid base64 length' => ['cursor=a'];
        foreach ([
            'missing fields' => '{}',
            'wrong types' => '{"createdAt":42,"id":false}',
            'invalid calendar date' => '{"createdAt":"2026-02-30T00:00:00Z","id":"00000000-0000-4000-8000-000000000001"}',
            'malformed date' => '{"createdAt":"not-a-date","id":"00000000-0000-4000-8000-000000000001"}',
        ] as $case => $json) {
            yield $case => ['cursor='.rtrim(strtr(base64_encode($json), '+/', '-_'), '=')];
        }
    }

    public function testInvitationCreationBodyIsAbsent(): void
    {
        $this->client->request('POST', '/api/v1/connect-invitations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->access[0], 'CONTENT_TYPE' => 'application/json'], content: '{}');
        $this->failure(400, 'INVALID_REQUEST');
    }

    public function testRegistrationBindsInvitationWithoutConsumingItAndActivatedUserAcceptsExactlyOnce(): void
    {
        $token = $this->newInvitation();
        $key = Uuid::v4()->toRfc4122();
        $this->registerInvited($token, $key);
        self::assertResponseStatusCodeSame(201);
        $registered = $this->body();
        $userId = $registered['user']['id'];
        self::assertIsString($userId);
        self::assertFalse($registered['user']['isActive']);
        self::assertTrue($registered['activationRequired']);
        $pendingId = $this->pendingId($userId);
        self::assertSame(substr($token, 0, 36), $pendingId);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect'));
        self::assertNull($this->db->fetchOne('SELECT used_at FROM connect_invitation WHERE id = ?', [$pendingId]));

        $access = $this->activateInvited($userId);
        // Activation issues a session; the separate protected continuation consumes the invitation.
        self::assertSame($pendingId, $this->pendingId($userId));
        $this->request('POST', '/api/v1/connect-invitations/pending/accept', $access);
        self::assertResponseStatusCodeSame(201);
        $accepted = $this->body();
        self::assertSame('created', $accepted['invitationOutcome']);
        self::assertSame($this->users[0], $accepted['otherUser']['id']);
        self::assertArrayNotHasKey('email', $accepted['otherUser']);
        self::assertNull($this->pendingId($userId));
        self::assertSame($userId, $this->db->fetchOne('SELECT accepted_by_id FROM connect_invitation WHERE id = ?', [$pendingId]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect'));

        $this->request('POST', '/api/v1/connect-invitations/pending/accept', $access);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->registerInvited($token, $key);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($registered, $this->body());
        self::assertNull($this->pendingId($userId));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM email_delivery_outbox'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect'));
    }

    #[DataProvider('unavailableRegistrationInvitations')]
    public function testUnavailableInvitationRollsBackRegistrationAndEmailIntent(string $case, int $status, string $code): void
    {
        $token = $this->newInvitation();
        if ('invalid' === $case) {
            $token = 'synthetic-invalid-invitation';
        } elseif ('expired' === $case) {
            $this->expireInvitation();
        } elseif ('used' === $case) {
            $this->request('POST', '/api/v1/connect-invitations/'.$token.'/accept', $this->access[1]);
            self::assertResponseStatusCodeSame(201);
        } else {
            $this->db->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$this->users[0]]);
        }
        $before = $this->registrationCounts();

        $this->registerInvited($token, Uuid::v4()->toRfc4122());

        $this->failure($status, $code);
        self::assertSame($before, $this->registrationCounts());
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function unavailableRegistrationInvitations(): iterable
    {
        yield 'invalid' => ['invalid', 404, 'RESOURCE_NOT_FOUND'];
        yield 'expired' => ['expired', 410, 'TOKEN_EXPIRED'];
        yield 'used' => ['used', 410, 'TOKEN_USED'];
        yield 'inactive author' => ['inactive', 409, 'INACTIVE_USER'];
    }

    #[DataProvider('unavailablePendingInvitations')]
    public function testFailedContinuationKeepsPendingInvitationAndDoesNotCreateAConnection(string $case, int $status, string $code): void
    {
        $token = $this->newInvitation();
        $this->registerInvited($token, Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(201);
        $userId = $this->body()['user']['id'];
        self::assertIsString($userId);
        $access = $this->activateInvited($userId);
        if ('expired' === $case) {
            $this->expireInvitation();
        } elseif ('used' === $case) {
            $this->request('POST', '/api/v1/connect-invitations/'.$token.'/accept', $this->access[1]);
            self::assertResponseStatusCodeSame(201);
        } elseif ('missing' === $case) {
            $this->db->executeStatement('UPDATE app_user SET pending_invitation_id = ? WHERE id = ?', [Uuid::v4()->toRfc4122(), $userId]);
            self::getContainer()->get(EntityManagerInterface::class)->clear();
        } else {
            $this->db->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$this->users[0]]);
        }
        $pendingId = $this->pendingId($userId);
        $before = $this->registrationCounts();

        $this->request('POST', '/api/v1/connect-invitations/pending/accept', $access);

        $this->failure($status, $code);
        self::assertSame($pendingId, $this->pendingId($userId));
        self::assertSame($before, $this->registrationCounts());
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function unavailablePendingInvitations(): iterable
    {
        yield 'expired' => ['expired', 410, 'TOKEN_EXPIRED'];
        yield 'used by another person' => ['used', 410, 'TOKEN_USED'];
        yield 'missing record' => ['missing', 404, 'RESOURCE_NOT_FOUND'];
        yield 'inactive author' => ['inactive', 409, 'INACTIVE_USER'];
    }

    public function testPendingContinuationWithoutInvitationIsEmptyAndRejectsABody(): void
    {
        $this->request('POST', '/api/v1/connect-invitations/pending/accept', $this->access[0]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        $this->client->request('POST', '/api/v1/connect-invitations/pending/accept', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->access[0], 'CONTENT_TYPE' => 'application/json',
        ], content: '{}');
        $this->failure(400, 'INVALID_REQUEST');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect'));
    }

    private function newInvitation(): string
    {
        $this->request('POST', '/api/v1/connect-invitations', $this->access[0]);
        self::assertResponseStatusCodeSame(200);
        $token = $this->body()['token'];
        self::assertIsString($token);

        return $token;
    }

    private function expireInvitation(): void
    {
        $this->db->executeStatement("UPDATE connect_invitation SET created_at = now() - INTERVAL '8 days', expires_at = now() - INTERVAL '1 day' WHERE created_by_id = ? AND used_at IS NULL", [$this->users[0]]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function registerInvited(string $token, string $key): void
    {
        $this->client->request('POST', '/api/v1/auth/register', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key, 'REMOTE_ADDR' => '192.0.2.42',
        ], content: json_encode(['name' => 'Invited newcomer', 'email' => 'newcomer@example.test', 'password' => 'New password1!', 'invitationToken' => $token], JSON_THROW_ON_ERROR));
    }

    private function activateInvited(string $userId): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $outbox = $em->getRepository(EmailDeliveryOutboxRecord::class)->findOneBy(['recipientEmail' => 'newcomer@example.test']);
        self::assertInstanceOf(EmailDeliveryOutboxRecord::class, $outbox);
        $encrypted = $outbox->encryptedPayload();
        self::assertNotNull($encrypted);
        $payload = self::getContainer()->get(PayloadCipher::class)->decrypt($encrypted);
        $this->client->request('POST', '/api/v1/auth/activate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['token' => $payload['token']], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($userId, $this->body()['user']['id']);
        $access = $this->body()['accessToken'];
        self::assertIsString($access);

        return $access;
    }

    private function pendingId(string $userId): ?string
    {
        $value = $this->db->fetchOne('SELECT pending_invitation_id FROM app_user WHERE id = ?', [$userId]);
        self::assertTrue(null === $value || is_string($value));

        return $value;
    }

    /** @return array<string, int> */
    private function registrationCounts(): array
    {
        $counts = [];
        foreach (['app_user', 'user_action_token', 'email_delivery_outbox', 'idempotency_record', 'user_session', 'connect'] as $table) {
            $counts[$table] = (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$table);
        }

        return $counts;
    }

    private function request(string $method, string $path, ?string $access = null): void
    {
        $this->client->request($method, $path, server: null === $access ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$access]);
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function failure(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame($code, $this->body()['error']['code']);
        self::assertResponseHasHeader('X-Request-Id');
    }
}
