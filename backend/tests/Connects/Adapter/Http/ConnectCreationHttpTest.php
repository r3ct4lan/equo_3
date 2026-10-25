<?php

declare(strict_types=1);

namespace App\Tests\Connects\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ConnectCreationHttpTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    /** @var list<string> */ private array $users = [];
    /** @var list<string> */ private array $access = [];
    private string $debtId;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        foreach (['EQUO_JWT_SIGNING_KEY_VERSION' => 'v1', 'EQUO_JWT_SIGNING_PRIVATE_KEY' => base64_encode($pem), 'EQUO_JWT_PUBLIC_KEY_RING' => json_encode(['v1' => base64_encode($details['key'])], JSON_THROW_ON_ERROR), 'EQUO_JWT_ISSUER' => 'https://equo.test', 'EQUO_JWT_AUDIENCE' => 'equo-api'] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->db = $container->get(Connection::class);
        $this->db->beginTransaction();
        for ($index = 0; $index < 4; ++$index) {
            $id = Uuid::v4()->toRfc4122();
            $this->users[] = $id;
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Shared debt user '.$index, 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => true, 'created_at' => '2026-10-25 10:00:00+00'], ['is_active' => 'boolean']);
            $this->access[] = $container->get(AccessTokenIssuerPort::class)->issue($id)->accessToken;
        }
        $this->debtId = Uuid::v4()->toRfc4122();
        $this->db->insert('debt', ['id' => $this->debtId, 'title' => 'Shared context', 'description' => null, 'total_amount' => 900, 'payer_id' => $this->users[0], 'created_by_id' => $this->users[0], 'created_at' => '2026-10-25 10:00:00+00', 'is_deleted' => false, 'version' => 1], ['is_deleted' => 'boolean']);
        foreach (array_slice($this->users, 0, 3) as $userId) {
            $this->db->insert('debt_participant', ['id' => Uuid::v4()->toRfc4122(), 'debt_id' => $this->debtId, 'user_id' => $userId, 'created_at' => '2026-10-25 10:00:00+00', 'is_deleted' => false], ['is_deleted' => 'boolean']);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testCurrentParticipantsCreateReplayAndReturnCanonicalExistingPair(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $this->request(1, $this->users[2], $this->debtId, $key);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $created = $this->body();
        self::assertResponseHeaderSame('Location', '/api/v1/connects/'.$created['id']);
        self::assertSame($this->users[2], $created['otherUser']['id']);
        self::assertSame(0, $created['balanceAmount']);
        self::assertStringNotContainsString('@example.test', json_encode($created, JSON_THROW_ON_ERROR));

        $this->request(1, $this->users[2], $this->debtId, $key);
        self::assertResponseStatusCodeSame(201);
        self::assertEquals($created, $this->body());

        $this->request(1, $this->users[2], $this->debtId, Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(200);
        self::assertSame($created['id'], $this->body()['id']);
        $pair = [$this->users[1], $this->users[2]];
        sort($pair, SORT_STRING);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect WHERE first_user_id=? AND second_user_id=?', $pair));
    }

    public function testIdempotencyKeyRejectsChangedRequestAndIsScopedByUser(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $this->request(1, $this->users[2], $this->debtId, $key);
        self::assertResponseStatusCodeSame(201);
        $this->request(1, $this->users[0], $this->debtId, $key);
        $this->failure(409, 'IDEMPOTENCY_KEY_REUSED');

        $this->request(2, $this->users[1], $this->debtId, $key);
        self::assertResponseStatusCodeSame(200);
    }

    public function testRequiresActiveCurrentParticipantsAndUndeletedDebtWithoutLeakingIt(): void
    {
        $this->request(3, $this->users[2], $this->debtId, Uuid::v4()->toRfc4122());
        $this->failure(404, 'DEBT_NOT_AVAILABLE');
        $hidden = $this->body()['error'];

        $this->db->executeStatement('UPDATE debt_participant SET is_deleted=true WHERE debt_id=? AND user_id=?', [$this->debtId, $this->users[2]]);
        $this->request(1, $this->users[2], $this->debtId, Uuid::v4()->toRfc4122());
        $this->failure(404, 'DEBT_NOT_AVAILABLE');
        self::assertSame($hidden['message'], $this->body()['error']['message']);

        $this->db->executeStatement('UPDATE debt_participant SET is_deleted=false WHERE debt_id=? AND user_id=?', [$this->debtId, $this->users[2]]);
        $this->db->update('debt', ['is_deleted' => true], ['id' => $this->debtId], ['is_deleted' => 'boolean']);
        $this->request(1, $this->users[2], $this->debtId, Uuid::v4()->toRfc4122());
        $this->failure(404, 'DEBT_NOT_AVAILABLE');

        $this->db->update('debt', ['is_deleted' => false], ['id' => $this->debtId], ['is_deleted' => 'boolean']);
        $this->db->update('app_user', ['is_active' => false], ['id' => $this->users[2]], ['is_active' => 'boolean']);
        $this->request(1, $this->users[2], $this->debtId, Uuid::v4()->toRfc4122());
        $this->failure(409, 'INACTIVE_USER');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect WHERE first_user_id IN (?,?) AND second_user_id IN (?,?)', [$this->users[1], $this->users[2], $this->users[1], $this->users[2]]));
    }

    public function testAuthenticationHeadersAndPayloadAreStrict(): void
    {
        $this->request(1, $this->users[1], $this->debtId, Uuid::v4()->toRfc4122());
        $this->failure(422, 'VALIDATION_ERROR');

        $this->request(1, $this->users[2], $this->debtId, null);
        $this->failure(400, 'IDEMPOTENCY_KEY_REQUIRED');
        $this->request(1, $this->users[2], $this->debtId, 'invalid');
        $this->failure(400, 'INVALID_REQUEST');

        $this->client->request('POST', '/api/v1/connects', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => Uuid::v4()->toRfc4122()], content: json_encode(['otherUserId' => $this->users[2], 'sharedDebtId' => $this->debtId], JSON_THROW_ON_ERROR));
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
        $this->client->request('POST', '/api/v1/connects', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->access[1], 'HTTP_IDEMPOTENCY_KEY' => Uuid::v4()->toRfc4122()], content: json_encode(['otherUserId' => $this->users[2], 'sharedDebtId' => $this->debtId, 'extra' => true], JSON_THROW_ON_ERROR));
        $this->failure(400, 'INVALID_REQUEST');
    }

    private function request(int $viewer, string $otherUserId, string $sharedDebtId, ?string $key): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->access[$viewer]];
        if (null !== $key) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        $this->client->request('POST', '/api/v1/connects', server: $headers, content: json_encode(['otherUserId' => $otherUserId, 'sharedDebtId' => $sharedDebtId], JSON_THROW_ON_ERROR));
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
