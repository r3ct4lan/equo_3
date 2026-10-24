<?php

declare(strict_types=1);

namespace App\Tests\Transfers\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ManualTransferHttpTest extends WebTestCase
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
        foreach (['EQUO_JWT_SIGNING_KEY_VERSION' => 'v1', 'EQUO_JWT_SIGNING_PRIVATE_KEY' => base64_encode($pem), 'EQUO_JWT_PUBLIC_KEY_RING' => json_encode(['v1' => base64_encode($details['key'])], JSON_THROW_ON_ERROR), 'EQUO_JWT_ISSUER' => 'https://equo.test', 'EQUO_JWT_AUDIENCE' => 'equo-api'] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->client = self::createClient();
        $this->client->disableReboot();
        $c = self::getContainer();
        $this->db = $c->get(Connection::class);
        $this->db->beginTransaction();
        for ($n = 0; $n < 4; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->users[] = $id;
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Financial person '.$n, 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00']);
            $this->access[] = $c->get(AccessTokenIssuerPort::class)->issue($id)->accessToken;
        }
        foreach ([1, 2] as $n) {
            $pair = [$this->users[0], $this->users[$n]];
            sort($pair);
            $this->db->insert('connect', ['id' => Uuid::v4()->toRfc4122(), 'first_user_id' => $pair[0], 'second_user_id' => $pair[1], 'created_at' => '2026-10-01 00:00:00+00']);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        } parent::tearDown();
    }

    private function connect(int $other = 1): string
    {
        $pair = [$this->users[0], $this->users[$other]];
        sort($pair);

        return (string) $this->db->fetchOne('SELECT id FROM connect WHERE first_user_id=? AND second_user_id=?', $pair);
    }

    /** @return array<string,mixed> */
    private function command(): array
    {
        return ['senderId' => $this->users[1], 'receiverId' => $this->users[0], 'amount' => 700];
    }

    /** @param array<string,mixed>|null $body */
    private function request(string $method, string $path, ?int $user = 0, ?array $body = null, ?string $key = null, ?string $etag = null): void
    {
        $headers = null === $user ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$this->access[$user]];
        if (null !== $body) {
            $headers['CONTENT_TYPE'] = 'application/json';
        }
        if (null !== $key) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        if (null !== $etag) {
            $headers['HTTP_IF_MATCH'] = $etag;
        }
        $this->client->request($method, $path, server: $headers, content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));
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

    /** @param array<string, mixed> $payload */
    private function assertNoForeignEmails(array $payload, int $viewer): void
    {
        $response = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ($this->users as $index => $id) {
            if ($index !== $viewer) {
                self::assertStringNotContainsString($id.'@example.test', $response);
            }
        }
    }

    /** @return array<string,mixed> */
    private function createManual(): array
    {
        $this->request('POST', '/api/v1/connects/'.$this->connect().'/transfers', 0, $this->command(), Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(201);

        return $this->body();
    }

    public function testBothDirectionsAuthorsReplayAndOverpayment(): void
    {
        $connect = $this->connect();
        $path = '/api/v1/connects/'.$connect.'/transfers';
        $key = Uuid::v4()->toRfc4122();
        $this->request('POST', $path, 0, $this->command(), $key);
        self::assertResponseStatusCodeSame(201);
        $first = $this->body();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('Location', '/api/v1/transfers/'.$first['id']);
        self::assertResponseHeaderSame('ETag', '"1"');
        self::assertSame($this->users[0], $first['createdBy']['id']);
        self::assertNull($first['debtReference']);
        self::assertNull($first['relatedTransferId']);
        $this->request('POST', $path, 0, $this->command(), $key);
        self::assertResponseStatusCodeSame(201);
        self::assertEquals($first, $this->body());
        self::assertResponseHeaderSame('Location', '/api/v1/transfers/'.$first['id']);
        self::assertResponseHeaderSame('ETag', '"1"');
        $changedPair = $this->command();
        $changedPair['senderId'] = $this->users[3];
        $this->request('POST', $path, 0, $changedPair, $key);
        $this->failure(409, 'IDEMPOTENCY_KEY_REUSED');
        $command = $this->command();
        $command['amount'] = 1001;
        $this->request('POST', $path, 0, $command, $key);
        $this->failure(409, 'IDEMPOTENCY_KEY_REUSED');
        $command['senderId'] = $this->users[0];
        $command['receiverId'] = $this->users[1];
        $this->request('POST', $path, 1, $command, Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(201);
        self::assertSame($this->users[1], $this->body()['createdBy']['id']);
        foreach ([0 => 301, 1 => -301] as $viewer => $amount) {
            $this->request('GET', '/api/v1/connects/'.$connect, $viewer);
            $connectView = $this->body();
            self::assertSame($amount, $connectView['balanceAmount']);
            $this->assertNoForeignEmails($connectView, $viewer);
            $this->request('GET', $path, $viewer);
            $history = $this->body();
            self::assertCount(2, $history['items']);
            $this->assertNoForeignEmails($history, $viewer);
            $this->request('GET', '/api/v1/transfers/'.$first['id'], $viewer);
            self::assertResponseStatusCodeSame(200);
            self::assertResponseHeaderSame('ETag', '"1"');
            $transfer = $this->body();
            self::assertSame('MANUAL', $transfer['type']);
            $this->assertNoForeignEmails($transfer, $viewer);
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt WHERE created_by_id IN (?,?)', [$this->users[0], $this->users[1]]));
        self::assertStringNotContainsString('@example.test', json_encode($first, JSON_THROW_ON_ERROR));
    }

    public function testHiddenConnectActiveSidesAndKeyRequirements(): void
    {
        $path = '/api/v1/connects/'.$this->connect().'/transfers';
        $hiddenConnectError = null;
        foreach ([$path, '/api/v1/connects/'.Uuid::v4()->toRfc4122().'/transfers', '/api/v1/connects/invalid/transfers'] as $route) {
            $this->request('POST', $route, 3, $this->command(), Uuid::v4()->toRfc4122());
            $this->failure(404, 'RESOURCE_NOT_FOUND');
            $error = $this->body()['error'];
            if (null !== $hiddenConnectError) {
                self::assertSame($hiddenConnectError['code'], $error['code']);
                self::assertSame($hiddenConnectError['message'], $error['message']);
            }
            $hiddenConnectError = $error;
            foreach (['Financial person', 'senderId', 'password', 'SQL', 'trace', '@example.test'] as $secret) {
                self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());
            }
        }
        $this->request('POST', $path, null, $this->command(), Uuid::v4()->toRfc4122());
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
        $this->request('POST', $path, 0, $this->command());
        $this->failure(400, 'IDEMPOTENCY_KEY_REQUIRED');
        $this->request('POST', $path, 0, $this->command(), 'invalid');
        $this->failure(400, 'INVALID_REQUEST');
        $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$this->users[1]]);
        $this->request('POST', $path, 0, $this->command(), Uuid::v4()->toRfc4122());
        $this->failure(409, 'INACTIVE_USER');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM transfer WHERE connect_id=?', [$this->connect()]));
    }

    #[DataProvider('invalidWire')]
    public function testRejectsInvalidWireWithoutEffects(string $field, mixed $value, int $status): void
    {
        $body = $this->command();
        $body[$field] = $value;
        $this->request('POST', '/api/v1/connects/'.$this->connect().'/transfers', 0, $body, Uuid::v4()->toRfc4122());
        $this->failure($status, 400 === $status ? 'INVALID_REQUEST' : 'VALIDATION_ERROR');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM transfer WHERE connect_id=?', [$this->connect()]));
    }

    /** @return iterable<string,array{string,mixed,int}> */
    public static function invalidWire(): iterable
    {
        foreach ([0, -1, 2147483648, 1.5, '100', true, null, [], (object) []] as $n => $value) {
            yield 'amount '.$n => ['amount', $value, 422];
        }
        yield 'sender uuid' => ['senderId', 'invalid', 422];
        yield 'receiver type' => ['receiverId', [], 422];
        yield 'extra field' => ['createdById', 'ignored', 400];
        yield 'deferred relation' => ['relatedTransferId', null, 400];
    }

    public function testWrongPairSelfTransferMissingFieldAndCrossTargetKey(): void
    {
        $path = '/api/v1/connects/'.$this->connect().'/transfers';
        foreach ([$this->users[0], $this->users[2], $this->users[3]] as $sender) {
            $body = $this->command();
            $body['senderId'] = $sender;
            $this->request('POST', $path, 0, $body, Uuid::v4()->toRfc4122());
            $this->failure(422, 'VALIDATION_ERROR');
        }
        $body = $this->command();
        unset($body['amount']);
        $this->request('POST', $path, 0, $body, Uuid::v4()->toRfc4122());
        $this->failure(422, 'VALIDATION_ERROR');
        $key = Uuid::v4()->toRfc4122();
        $this->request('POST', $path, 0, $this->command(), $key);
        self::assertResponseStatusCodeSame(201);
        $body = $this->command();
        $body['senderId'] = $this->users[2];
        $this->request('POST', '/api/v1/connects/'.$this->connect(2).'/transfers', 0, $body, $key);
        $this->failure(409, 'IDEMPOTENCY_KEY_REUSED');
    }

    public function testUpdateSupportsAmountDirectionNoOpAndVersionCheckedBalance(): void
    {
        $manual = $this->createManual();
        $path = '/api/v1/transfers/'.$manual['id'];
        $update = ['senderId' => $this->users[0], 'receiverId' => $this->users[1], 'amount' => 900];
        $this->request('PUT', $path, 0, $update, etag: '"1"');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('ETag', '"2"');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSame(2, $this->body()['version']);
        self::assertSame(900, $this->body()['amount']);
        self::assertSame($this->users[0], $this->body()['sender']['id']);
        self::assertSame($this->users[1], $this->body()['receiver']['id']);
        $this->request('GET', '/api/v1/connects/'.$this->connect(), 0);
        self::assertSame(900, $this->body()['balanceAmount']);
        $this->request('PUT', $path, 0, $update, etag: '"2"');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('ETag', '"2"');
        $this->request('PUT', $path, 0, ['senderId' => $this->users[1], 'receiverId' => $this->users[0], 'amount' => 800], etag: '"1"');
        $this->failure(409, 'VERSION_CONFLICT');
        self::assertSame(['expectedVersion' => 1, 'actualVersion' => 2], $this->body()['error']['details']);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT version FROM transfer WHERE id=?', [$manual['id']]));
    }

    public function testDeleteSoftRemovesManualFromBalanceAndPreservesBothHistoryViews(): void
    {
        $manual = $this->createManual();
        $path = '/api/v1/transfers/'.$manual['id'];
        $this->request('DELETE', $path, 0, etag: '"1"');
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        foreach ([0, 1] as $viewer) {
            $this->request('GET', $path, $viewer);
            self::assertResponseStatusCodeSame(200);
            self::assertResponseHeaderSame('ETag', '"2"');
            self::assertTrue($this->body()['isDeleted']);
            self::assertFalse($this->body()['canEdit']);
            self::assertFalse($this->body()['canDelete']);
            $this->request('GET', '/api/v1/connects/'.$this->connect(), $viewer);
            self::assertSame(0, $this->body()['balanceAmount']);
            $this->request('GET', '/api/v1/connects/'.$this->connect().'/transfers', $viewer);
            self::assertCount(1, $this->body()['items']);
            self::assertTrue($this->body()['items'][0]['isDeleted']);
        }
        $this->request('DELETE', $path, 0, etag: '"2"');
        $this->failure(409, 'TRANSFER_DELETED');
    }

    public function testWritersEnforceAclActiveSidesOriginalPairAndAutomaticImmutability(): void
    {
        $manual = $this->createManual();
        $path = '/api/v1/transfers/'.$manual['id'];
        $update = ['senderId' => $this->users[0], 'receiverId' => $this->users[1], 'amount' => 900];
        $this->request('PUT', $path, 1, $update, etag: '"1"');
        $this->failure(403, 'FORBIDDEN');
        $this->request('DELETE', $path, 3, etag: '"1"');
        $this->failure(404, 'TRANSFER_NOT_AVAILABLE');
        $wrongPair = ['senderId' => $this->users[0], 'receiverId' => $this->users[2], 'amount' => 900];
        $this->request('PUT', $path, 0, $wrongPair, etag: '"1"');
        $this->failure(422, 'VALIDATION_ERROR');
        $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$this->users[1]]);
        $this->request('PUT', $path, 0, $update, etag: '"1"');
        $this->failure(409, 'TRANSFER_FROZEN');
        $this->db->executeStatement('UPDATE app_user SET is_active=true WHERE id=?', [$this->users[1]]);

        $debt = ['title' => 'Automatic', 'description' => null, 'totalAmount' => 100, 'payerId' => $this->users[0], 'participantIds' => [$this->users[1]], 'includePayerInSplit' => false];
        $this->request('POST', '/api/v1/debts', 0, $debt, Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(201);
        $shareId = $this->body()['participants'][0]['transferId'];
        self::assertIsString($shareId);
        foreach (['PUT', 'DELETE'] as $method) {
            $this->request($method, '/api/v1/transfers/'.$shareId, 0, 'PUT' === $method ? $update : null, etag: '"1"');
            $this->failure(403, 'AUTOMATIC_TRANSFER_IMMUTABLE');
        }
        self::assertFalse((bool) $this->db->fetchOne('SELECT is_deleted FROM transfer WHERE id=?', [$manual['id']]));
    }

    public function testWritersRequireStrictIfMatchAndRejectDeferredOrUnknownFields(): void
    {
        $manual = $this->createManual();
        $path = '/api/v1/transfers/'.$manual['id'];
        $update = ['senderId' => $this->users[0], 'receiverId' => $this->users[1], 'amount' => 900];
        foreach ([null, ''] as $etag) {
            $this->request('PUT', $path, 0, $update, etag: $etag);
            $this->failure(428, 'PRECONDITION_REQUIRED');
        }
        foreach (['1', 'W/"1"', '*', '"1","2"', '"0"', '"01"', '"2147483648"', '"1" garbage'] as $etag) {
            $this->request('DELETE', $path, 0, etag: $etag);
            $this->failure(400, 'INVALID_REQUEST');
        }
        foreach (['relatedTransferId' => null, 'createdById' => $this->users[0], 'version' => 1] as $field => $value) {
            $this->request('PUT', $path, 0, [...$update, $field => $value], etag: '"1"');
            $this->failure(400, 'INVALID_REQUEST');
        }
        $this->request('PUT', $path, null, $update, etag: '"1"');
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT version FROM transfer WHERE id=?', [$manual['id']]));
    }
}
