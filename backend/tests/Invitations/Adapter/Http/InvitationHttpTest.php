<?php

declare(strict_types=1);

namespace App\Tests\Invitations\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use Doctrine\DBAL\Connection;
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
    }

    public function testBodyIsAbsentAndUnsupportedFinancialRoutesAreNotPublished(): void
    {
        $this->client->request('POST', '/api/v1/connect-invitations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->access[0], 'CONTENT_TYPE' => 'application/json'], content: '{}');
        $this->failure(400, 'INVALID_REQUEST');
        $this->request('POST', '/api/v1/connects', $this->access[0]);
        self::assertResponseStatusCodeSame(405);
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
