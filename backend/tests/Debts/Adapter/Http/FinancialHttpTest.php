<?php

declare(strict_types=1);

namespace App\Tests\Debts\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class FinancialHttpTest extends WebTestCase
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

    /** @return array<string,mixed> */
    private function command(): array
    {
        return ['title' => 'Dinner', 'description' => null, 'totalAmount' => 1001, 'payerId' => $this->users[0], 'participantIds' => [$this->users[1], $this->users[2]], 'includePayerInSplit' => true];
    }

    /** @param array<string,mixed>|null $body */
    private function request(string $method, string $path, ?int $user = 0, ?array $body = null, ?string $key = null): void
    {
        $headers = null === $user ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$this->access[$user]];
        if (null !== $body) {
            $headers['CONTENT_TYPE'] = 'application/json';
        }
        if (null !== $key) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
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

    /** @return array<string,mixed> */
    private function create(): array
    {
        $this->request('POST', '/api/v1/debts', 0, $this->command(), Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(201);

        return $this->body();
    }

    public function testCreateReplayReadAndPerspectiveBalances(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $command = $this->command();
        $this->request('POST', '/api/v1/debts', 0, $command, $key);
        self::assertResponseStatusCodeSame(201);
        $debt = $this->body();
        self::assertResponseHeaderSame('ETag', '"1"');
        self::assertResponseHeaderSame('Location', '/api/v1/debts/'.$debt['id']);
        $this->request('POST', '/api/v1/debts', 0, $command, $key);
        self::assertResponseStatusCodeSame(201);
        self::assertEquals($debt, $this->body());
        $command['totalAmount'] = 2000;
        $this->request('POST', '/api/v1/debts', 0, $command, $key);
        $this->failure(409, 'IDEMPOTENCY_KEY_REUSED');
        $participant = array_values(array_filter($debt['participants'], fn (array $p): bool => $p['user']['id'] === $this->users[1]))[0];
        $this->request('GET', '/api/v1/transfers/'.$participant['transferId'], 1);
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('ETag', '"1"');
        $transfer = $this->body();
        self::assertSame(334, $transfer['amount']);
        self::assertFalse($transfer['canEdit']);
        self::assertFalse($transfer['canDelete']);
        self::assertNull($transfer['createdBy']);
        self::assertTrue($transfer['debtReference']['isAccessible']);
        foreach ([0 => 334, 1 => -334] as $viewer => $expected) {
            $this->request('GET', '/api/v1/connects/'.$transfer['connectId'], $viewer);
            self::assertSame($expected, $this->body()['balanceAmount']);
            $this->request('GET', '/api/v1/connects', $viewer);
            $rows = array_column($this->body()['items'], null, 'id');
            self::assertSame($expected, $rows[$transfer['connectId']]['balanceAmount']);
            $this->request('GET', '/api/v1/connects/'.$transfer['connectId'].'/transfers', $viewer);
            self::assertSame($transfer['id'], $this->body()['items'][0]['id']);
        }
        foreach ([0, 1, 2] as $viewer) {
            $this->request('GET', '/api/v1/debts/'.$debt['id'], $viewer);
            self::assertResponseStatusCodeSame(200);
            self::assertSame(0 === $viewer, $this->body()['canEdit']);
            $this->request('GET', '/api/v1/debts', $viewer);
            self::assertSame($debt['id'], $this->body()['items'][0]['id']);
            self::assertSame(3, $this->body()['items'][0]['participantCount']);
        }
        $this->request('GET', '/api/v1/debts', 3);
        self::assertSame([], $this->body()['items']);
        $this->request('GET', '/api/v1/debts/'.$debt['id'], 3);
        $this->failure(404, 'DEBT_NOT_AVAILABLE');
        $hiddenDebtError = $this->body()['error'];
        $this->request('GET', '/api/v1/debts/'.Uuid::v4()->toRfc4122(), 3);
        $this->failure(404, 'DEBT_NOT_AVAILABLE');
        self::assertSame($hiddenDebtError['code'], $this->body()['error']['code']);
        self::assertSame($hiddenDebtError['message'], $this->body()['error']['message']);
        $this->request('GET', '/api/v1/transfers/'.$transfer['id'], 2);
        $this->failure(404, 'TRANSFER_NOT_AVAILABLE');
        $hiddenTransferError = $this->body()['error'];
        $this->request('GET', '/api/v1/transfers/'.Uuid::v4()->toRfc4122(), 2);
        $this->failure(404, 'TRANSFER_NOT_AVAILABLE');
        self::assertSame($hiddenTransferError['code'], $this->body()['error']['code']);
        self::assertSame($hiddenTransferError['message'], $this->body()['error']['message']);
        $this->request('GET', '/api/v1/connects/'.$transfer['connectId'].'/transfers', 3);
        $this->failure(404, 'RESOURCE_NOT_FOUND');
        $hiddenHistoryError = $this->body()['error'];
        $this->request('GET', '/api/v1/connects/'.Uuid::v4()->toRfc4122().'/transfers', 3);
        $this->failure(404, 'RESOURCE_NOT_FOUND');
        self::assertSame($hiddenHistoryError['code'], $this->body()['error']['code']);
        self::assertSame($hiddenHistoryError['message'], $this->body()['error']['message']);
        self::assertStringNotContainsString('Dinner', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('@example.test', json_encode($debt, JSON_THROW_ON_ERROR));
    }

    public function testExcludedParticipantRetainsTransferWithoutLeakingDebtAndDeletionFreezesAccess(): void
    {
        $debt = $this->create();
        $p = array_values(array_filter($debt['participants'], fn (array $p): bool => $p['user']['id'] === $this->users[1]))[0];
        $this->db->executeStatement('UPDATE debt_participant SET is_deleted=true WHERE id=?', [$p['debtParticipantId']]);
        $this->db->executeStatement('UPDATE transfer SET is_deleted=true,version=2 WHERE id=?', [$p['transferId']]);
        $this->request('GET', '/api/v1/debts/'.$debt['id'], 1);
        $this->failure(404, 'DEBT_NOT_AVAILABLE');
        $this->request('GET', '/api/v1/debts', 1);
        self::assertSame([], $this->body()['items']);
        $this->request('GET', '/api/v1/transfers/'.$p['transferId'], 1);
        self::assertResponseStatusCodeSame(200);
        $t = $this->body();
        self::assertSame(['id' => $debt['id'], 'isAccessible' => false], $t['debtReference']);
        self::assertTrue($t['isDeleted']);
        self::assertResponseHeaderSame('ETag', '"2"');
        $this->request('GET', '/api/v1/connects/'.$t['connectId'], 1);
        self::assertSame(0, $this->body()['balanceAmount']);
        $this->request('GET', '/api/v1/connects/'.$t['connectId'].'/transfers', 1);
        self::assertTrue($this->body()['items'][0]['isDeleted']);
        $this->db->executeStatement('UPDATE debt SET is_deleted=true,version=2 WHERE id=?', [$debt['id']]);
        $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$this->users[0]]);
        $this->request('GET', '/api/v1/debts', 2);
        self::assertSame([], $this->body()['items']);
        $this->request('GET', '/api/v1/debts/'.$debt['id'], 2);
        self::assertResponseStatusCodeSame(200);
        $body = $this->body();
        self::assertTrue($body['isDeleted']);
        self::assertFalse($body['canEdit']);
        self::assertFalse($body['payer']['isActive']);
        self::assertSame(2, $body['participantCount']);
    }

    public function testExactWideBalanceIgnoresDeletedAndDependsOnlyOnLedger(): void
    {
        $pair = [$this->users[0], $this->users[1]];
        sort($pair);
        $connect = $this->db->fetchOne('SELECT id FROM connect WHERE first_user_id=? AND second_user_id=?', $pair);
        self::assertIsString($connect);
        $rows = [[0, 1, 2147483647, 0], [0, 1, 2147483647, 0], [1, 0, 99, 0], [0, 1, 700, 1]];
        foreach (array_reverse($rows) as [$s,$r,$amount,$deleted]) {
            $this->db->insert('transfer', ['id' => Uuid::v4()->toRfc4122(), 'connect_id' => $connect, 'sender_id' => $this->users[$s], 'receiver_id' => $this->users[$r], 'amount' => $amount, 'type' => 'MANUAL', 'created_by_id' => $this->users[$s], 'created_at' => '2026-10-01 00:00:00+00', 'is_deleted' => $deleted, 'version' => 1]);
        }
        foreach ([0 => 4294967195, 1 => -4294967195] as $viewer => $expected) {
            $this->request('GET', '/api/v1/connects/'.$connect, $viewer);
            self::assertSame($expected, $this->body()['balanceAmount']);
            $this->request('GET', '/api/v1/connects/'.$connect.'/transfers', $viewer);
            self::assertCount(4, $this->body()['items']);
        }
    }

    public function testKeysetPagesDoNotRepeatTiedDates(): void
    {
        $created = [];
        for ($n = 0; $n < 5; ++$n) {
            $created[] = $this->create()['id'];
        }
        $this->db->executeStatement("UPDATE debt SET created_at='2026-10-01 00:00:00+00' WHERE created_by_id=?", [$this->users[0]]);
        $seen = [];
        $cursor = null;
        do {
            $this->request('GET', '/api/v1/debts?limit=2'.(null === $cursor ? '' : '&cursor='.$cursor));
            $page = $this->body();
            $seen = array_merge($seen, array_column($page['items'], 'id'));
            $cursor = $page['pagination']['nextCursor'];
            self::assertSame(null !== $cursor, $page['pagination']['hasMore']);
        } while (null !== $cursor);
        rsort($created);
        self::assertSame($created, $seen);
        $connect = $this->db->fetchOne('SELECT connect_id FROM transfer WHERE receiver_id=? LIMIT 1', [$this->users[1]]);
        self::assertIsString($connect);
        $this->db->executeStatement("UPDATE transfer SET created_at='2026-10-01 00:00:00+00' WHERE connect_id=?", [$connect]);
        $seen = [];
        $cursor = null;
        do {
            $this->request('GET', '/api/v1/connects/'.$connect.'/transfers?limit=2'.(null === $cursor ? '' : '&cursor='.$cursor), 1);
            $page = $this->body();
            $seen = array_merge($seen, array_column($page['items'], 'id'));
            $cursor = $page['pagination']['nextCursor'];
        } while (null !== $cursor);
        self::assertCount(5, array_unique($seen));
        $expected = $this->db->fetchFirstColumn('SELECT id FROM transfer WHERE connect_id=? ORDER BY created_at DESC,id DESC', [$connect]);
        self::assertSame($expected, $seen);
    }

    #[DataProvider('invalidCommands')]
    public function testInvalidWireTypesAndValuesDoNotWrite(string $field, mixed $value): void
    {
        $command = $this->command();
        $command[$field] = $value;
        $this->request('POST', '/api/v1/debts', 0, $command, Uuid::v4()->toRfc4122());
        $this->failure(422, 'VALIDATION_ERROR');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt WHERE created_by_id=?', [$this->users[0]]));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidCommands(): iterable
    {
        yield 'zero' => ['totalAmount', 0];
        yield 'overflow' => ['totalAmount', 2147483648];
        yield 'float' => ['totalAmount', 10.5];
        yield 'numeric string' => ['totalAmount', '1001'];
        yield 'bool' => ['includePayerInSplit', 'true'];
        yield 'empty' => ['participantIds', []];
        yield 'wrong member' => ['participantIds', [7]];
        yield 'title' => ['title', '  '];
    }

    public function testGeneratedLedgerPermutationsPreserveBalanceAndSign(): void
    {
        $pair = [$this->users[0], $this->users[1]];
        sort($pair);
        $connect = $this->db->fetchOne('SELECT id FROM connect WHERE first_user_id=? AND second_user_id=?', $pair);
        self::assertIsString($connect);
        $entries = [];
        $expected = 0;
        for ($n = 1; $n <= 32; ++$n) {
            $sender = $n % 2;
            $amount = ($n * 7919) % 100000 + 1;
            $deleted = 0 === $n % 5;
            $entries[] = ['id' => Uuid::v4()->toRfc4122(), 'connect_id' => $connect, 'sender_id' => $this->users[$sender], 'receiver_id' => $this->users[1 - $sender], 'amount' => $amount, 'type' => 'MANUAL', 'created_by_id' => $this->users[$sender], 'created_at' => '2026-10-01 00:00:00+00', 'is_deleted' => $deleted, 'version' => 1];
            if (!$deleted) {
                $expected += 0 === $sender ? $amount : -$amount;
            }
        }
        $alternate = [...array_slice($entries, 16), ...array_slice($entries, 0, 16)];
        foreach ([$entries, array_reverse($entries), $alternate] as $permutation) {
            $this->db->executeStatement('DELETE FROM transfer WHERE connect_id=?', [$connect]);
            foreach ($permutation as $entry) {
                $this->db->insert('transfer', $entry, ['is_deleted' => 'boolean']);
            }
            foreach ([0 => $expected, 1 => -$expected] as $viewer => $balance) {
                $this->request('GET', '/api/v1/connects/'.$connect, $viewer);
                self::assertResponseStatusCodeSame(200);
                self::assertSame($balance, $this->body()['balanceAmount']);
            }
        }
    }

    public function testRequiredHeadersProtectedPathsAndInvalidPagination(): void
    {
        foreach (['/api/v1/debts', '/api/v1/debts/invalid', '/api/v1/transfers/invalid', '/api/v1/connects/invalid/transfers'] as $path) {
            $this->request('GET', $path, null);
            $this->failure(401, 'AUTHENTICATION_REQUIRED');
        }
        $this->request('POST', '/api/v1/debts', null, $this->command(), Uuid::v4()->toRfc4122());
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt'));

        $this->request('POST', '/api/v1/debts', 0, $this->command());
        $this->failure(400, 'IDEMPOTENCY_KEY_REQUIRED');
        foreach (['limit=0', 'limit=101', 'limit[]=20', 'cursor=invalid', 'cursor[]=bad', 'otherUserId=foo'] as $q) {
            $this->request('GET', '/api/v1/debts?'.$q);
            $this->failure(400, 'INVALID_REQUEST');
        }
        $this->request('GET', '/api/v1/transfers/invalid');
        $this->failure(404, 'TRANSFER_NOT_AVAILABLE');
        $this->request('GET', '/api/v1/debts/invalid');
        $this->failure(404, 'DEBT_NOT_AVAILABLE');
    }
}
