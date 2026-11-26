<?php

declare(strict_types=1);

namespace App\Tests\Debts\Adapter\Http;

use App\Connects\Domain\CanonicalPair;
use App\Debts\Adapter\Persistence\Doctrine\DoctrineDebtRepository;
use App\Debts\Adapter\Persistence\Doctrine\DoctrineMergeExpenseRepository;
use App\Debts\Application\MergeExpenses;
use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\Infrastructure\Persistence\Doctrine\DoctrineMergeLedger;
use DateTimeImmutable;
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

    public function testReceiptUploadsRejectInvalidPhotosAndReplayOneDraft(): void
    {
        $path = '/api/v1/debts/receipt-uploads';
        $image = 'data:image/png;base64,'.base64_encode((string) file_get_contents(__DIR__.'/../../receipts/coffee-1.png'));
        foreach ([[], array_fill(0, 4, $image), ['data:image/png;base64,YQ==']] as $photos) {
            $this->request('POST', $path, 0, ['photos' => $photos], Uuid::v4()->toRfc4122());
            self::assertResponseStatusCodeSame(422);
        }
        $before = array_map(fn (string $table): int => (int) $this->db->fetchOne('SELECT count(*) FROM '.$table), ['debt', 'connect', 'transfer']);
        foreach ([1, 3] as $count) {
            $key = Uuid::v4()->toRfc4122();
            $body = ['photos' => array_fill(0, $count, $image)];
            $this->request('POST', $path, 0, $body, $key);
            self::assertResponseStatusCodeSame(202);
            $first = $this->body();
            $this->request('POST', $path, 0, $body, $key);
            self::assertSame($first, $this->body());
            $this->request('GET', $path.'/'.$first['id'], 1);
            self::assertResponseStatusCodeSame(404);
        }
        self::assertSame($before, array_map(fn (string $table): int => (int) $this->db->fetchOne('SELECT count(*) FROM '.$table), ['debt', 'connect', 'transfer']));
    }

    public function testCreateAndReadReceiptWithIndividualShares(): void
    {
        $image = 'data:image/png;base64,'.base64_encode((string) file_get_contents(__DIR__.'/../../receipts/coffee-1.png'));
        $this->request('POST', '/api/v1/debts/receipt-uploads', 0, ['photos' => [$image]], Uuid::v4()->toRfc4122());
        $upload = $this->body()['id'];
        [$anna, $boris] = $this->users;
        $line = static fn (string $name, int $amount, array $assignments): array => ['name' => $name, 'quantity' => '1', 'unitPrice' => $amount, 'amount' => $amount, 'assignments' => $assignments];
        $lines = [$line('Soup', 800, [$anna => null]), $line('Pasta', 1200, [$boris => null]), $line('Bread', 400, [$anna => null, $boris => null])];
        $receipt = ['uploadId' => $upload, 'lines' => $lines, 'discount' => 0, 'remainder' => []];
        $command = array_replace($this->command(), ['totalAmount' => 2400, 'receipt' => $receipt]);
        $key = Uuid::v4()->toRfc4122();
        $this->request('POST', '/api/v1/debts', 0, $command, $key);
        self::assertResponseStatusCodeSame(422);
        $command['currency'] = 'EUR';
        $this->request('POST', '/api/v1/debts', 0, $command, $key);
        self::assertResponseStatusCodeSame(201);
        $debt = $this->body();
        self::assertSame(2, $debt['participantCount']);
        self::assertSame(1400, (int) $this->db->fetchOne('SELECT amount FROM transfer WHERE debt_id=? AND receiver_id=?', [$debt['id'], $boris]));
        $this->request('POST', '/api/v1/debts', 0, $command, $key);
        self::assertSame($debt['id'], $this->body()['id']);
        $this->request('GET', '/api/v1/debts/'.$debt['id'], 1);
        self::assertEquals($lines, $this->body()['receipt']['lines']);
        self::assertSame([1], $this->body()['receipt']['photos']);
        $this->request('GET', '/api/v1/debts/'.$debt['id'], 2);
        self::assertResponseStatusCodeSame(404);
        $path = '/api/v1/debts/'.$debt['id'];
        $this->request('GET', $path.'/photos/1', 1);
        self::assertResponseIsSuccessful();
        self::assertSame($image, $this->body()['image']);
        $this->request('GET', $path.'/photos/1', 2);
        self::assertResponseStatusCodeSame(404);
        $this->request('DELETE', $path.'/photos/1', 1, etag: '"1"');
        self::assertResponseStatusCodeSame(403);

        unset($command['payerId']);
        $command['receipt']['lines'][1]['assignments'] = [$anna => null, $boris => null];
        $this->request('PUT', $path, 0, $command, etag: '"1"');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->body()['version']);
        self::assertSame(800, (int) $this->db->fetchOne('SELECT amount FROM transfer WHERE debt_id=? AND receiver_id=?', [$debt['id'], $boris]));
        $this->request('PUT', $path, 0, $command, etag: '"2"');
        self::assertSame(2, $this->body()['version']);
        $this->request('DELETE', $path.'/photos/1', 0, etag: '"1"');
        self::assertResponseStatusCodeSame(409);
        $this->request('DELETE', $path.'/photos/1', 0, etag: '"2"');
        self::assertResponseIsSuccessful();
        $this->request('GET', $path.'/photos/1', 1);
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<string,mixed> */
    private function command(): array
    {
        return ['title' => 'Dinner', 'description' => null, 'totalAmount' => 1001, 'payerId' => $this->users[0], 'participantIds' => [$this->users[1], $this->users[2]], 'includePayerInSplit' => true];
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
    private function create(): array
    {
        $this->request('POST', '/api/v1/debts', 0, $this->command(), Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(201);

        return $this->body();
    }

    public function testCurrencyOptionsRankOnlyTheViewersOwnSavedCurrencies(): void
    {
        $this->db->update('app_user', ['currency' => 'JPY'], ['id' => $this->users[0]]);
        $this->request('POST', '/api/v1/debts', 0, $this->command() + ['currency' => 'RSD'], Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(201);
        $debt = $this->body();
        $this->db->update('debt', ['created_at' => '2026-10-06'], ['id' => $debt['id']]);
        $pair = new CanonicalPair($this->users[0], $this->users[1]);
        $connect = $this->db->fetchOne('SELECT id FROM connect WHERE first_user_id = ? AND second_user_id = ?', [$pair->first, $pair->second]);
        self::assertIsString($connect);
        foreach (['CAD', 'USD', 'EUR', 'JPY', 'HUF', 'USD'] as $i => $currency) {
            $id = $this->addManualTransfer($connect, $this->users[0], $this->users[1], 100);
            $this->db->update('transfer', ['currency' => $currency, 'created_at' => '2026-10-0'.($i + 1)], ['id' => $id]);
        }
        $this->request('GET', '/api/v1/currencies/options');
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(json_encode(['JPY', 'RSD', 'USD', 'HUF', 'EUR', 'CAD', 'KWD'], JSON_THROW_ON_ERROR), (string) $this->client->getResponse()->getContent());

        $this->request('GET', '/api/v1/currencies/options', 1);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(json_encode(['EUR', 'CAD', 'HUF', 'JPY', 'KWD', 'RSD', 'USD'], JSON_THROW_ON_ERROR), (string) $this->client->getResponse()->getContent());
    }

    public function testMergedConnectionsKeepExternalLedgerAndHideInternalOperations(): void
    {
        [$primary, $joined, $friend, $other] = $this->users;
        $keptPair = new CanonicalPair($primary, $friend);
        $kept = $this->db->fetchOne('SELECT id FROM connect WHERE first_user_id = ? AND second_user_id = ?', [$keptPair->first, $keptPair->second]);
        $innerPair = new CanonicalPair($primary, $joined);
        $inner = $this->db->fetchOne('SELECT id FROM connect WHERE first_user_id = ? AND second_user_id = ?', [$innerPair->first, $innerPair->second]);
        self::assertIsString($kept);
        self::assertIsString($inner);
        $mergedPair = $this->addConnection($joined, $friend);
        $unrelated = $this->addConnection($friend, $other);
        $firstTransfer = $this->addManualTransfer($kept, $friend, $primary, 2000);
        $secondTransfer = $this->addManualTransfer($mergedPair, $joined, $friend, 5000);
        $innerTransfer = $this->addManualTransfer($inner, $primary, $joined, 1500);
        $otherTransfer = $this->addManualTransfer($unrelated, $friend, $other, 900);

        (new DoctrineMergeLedger($this->db))->apply($primary, $joined, new DateTimeImmutable());

        $this->request('GET', '/api/v1/connects/'.$kept);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(3000, $this->body()['balanceAmount']);
        $this->request('GET', '/api/v1/connects/'.$kept, 2);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(-3000, $this->body()['balanceAmount']);
        $this->request('GET', '/api/v1/connects/'.$kept.'/transfers', 2);
        self::assertResponseStatusCodeSame(200);
        $history = array_column($this->body()['items'], null, 'id');
        self::assertCount(2, $history);
        self::assertSame($friend, $history[$firstTransfer]['sender']['id']);
        self::assertSame($primary, $history[$secondTransfer]['sender']['id']);
        $this->request('GET', '/api/v1/connects/'.$mergedPair, 2);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', '/api/v1/connects/'.$inner);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', '/api/v1/transfers/'.$innerTransfer);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', '/api/v1/transfers/'.$otherTransfer, 3);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(900, $this->body()['amount']);
    }

    public function testManualReferenceFollowsTheCombinedShare(): void
    {
        [$primary, $joined, $payer] = $this->users;
        $source = $this->addConnection($joined, $payer);
        $this->request('POST', '/api/v1/debts', 2, [
            'title' => 'Shared meal', 'description' => null, 'totalAmount' => 6000, 'payerId' => $payer,
            'participantIds' => [$primary, $joined], 'includePayerInSplit' => true,
        ], Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(201);
        $debt = $this->body()['id'];
        $shares = array_column($this->db->fetchAllAssociative('SELECT receiver_id, id FROM transfer WHERE debt_id = ?', [$debt]), 'id', 'receiver_id');
        $manual = $this->addManualTransfer($source, $joined, $payer, 500);
        $this->db->update('transfer', ['related_transfer_id' => $shares[$joined]], ['id' => $manual]);

        (new MergeExpenses(new DoctrineDebtRepository($this->db), new DoctrineMergeExpenseRepository($this->db, self::getContainer()->get(\App\Transfers\Application\Api\ReconcileShare::class))))->apply($primary, $joined);
        (new DoctrineMergeLedger($this->db))->apply($primary, $joined, new DateTimeImmutable());

        $this->request('GET', '/api/v1/transfers/'.$manual, 2);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($shares[$primary], $this->body()['relatedTransferId']);
        self::assertSame($primary, $this->body()['sender']['id']);
    }

    private function addConnection(string $left, string $right): string
    {
        $pair = new CanonicalPair($left, $right);
        $id = Uuid::v4()->toRfc4122();
        $this->db->insert('connect', ['id' => $id, 'first_user_id' => $pair->first, 'second_user_id' => $pair->second, 'created_at' => '2026-10-01 00:00:00+00']);

        return $id;
    }

    private function addManualTransfer(string $connect, string $sender, string $receiver, int $amount): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->db->insert('transfer', [
            'id' => $id, 'connect_id' => $connect, 'sender_id' => $sender, 'receiver_id' => $receiver,
            'amount' => $amount, 'type' => 'MANUAL', 'created_by_id' => $sender, 'created_at' => '2026-10-01 00:00:00+00',
        ]);

        return $id;
    }

    public function testMergedSharesAgreeInExpenseDetailsHistoryAndBalance(): void
    {
        $debt = $this->create();
        $id = $debt['id'];
        self::assertIsString($id);
        $merge = new MergeExpenses(new DoctrineDebtRepository($this->db), new DoctrineMergeExpenseRepository($this->db, self::getContainer()->get(\App\Transfers\Application\Api\ReconcileShare::class)));
        $merge->apply($this->users[1], $this->users[2]);

        $this->request('GET', '/api/v1/debts/'.$id);
        self::assertResponseStatusCodeSame(200);
        $body = $this->body();
        self::assertNull($body['shareAmount']);
        self::assertSame(2, $body['participantCount']);
        $amounts = array_column($body['participants'], 'shareAmount');
        sort($amounts, SORT_NUMERIC);
        self::assertSame([334, 668], $amounts);

        $this->request('GET', '/api/v1/transfers?direction=outgoing');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([668], array_column($this->body()['items'], 'amount'));
        $this->request('GET', '/api/v1/transfers/balance-summary');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['currency' => 'EUR', 'totalOwedAmount' => 0, 'totalReceivableAmount' => 668], $this->body());

        $this->request('GET', '/api/v1/debts/'.$id, 2);
        self::assertResponseStatusCodeSame(404);
    }

    public function testBalanceSummaryIsPrivateEmptyAndRejectsQueryOverrides(): void
    {
        $this->request('GET', '/api/v1/transfers/balance-summary');
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSame(['currency' => 'EUR', 'totalOwedAmount' => 0, 'totalReceivableAmount' => 0], $this->body());
        $this->request('GET', '/api/v1/transfers/balance-summary?userId='.$this->users[1]);
        $this->failure(400, 'INVALID_REQUEST');
        $this->request('GET', '/api/v1/transfers/balance-summary', null);
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
        $this->client->request('GET', '/api/v1/transfers/balance-summary', server: ['HTTP_AUTHORIZATION' => 'Bearer invalid']);
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
    }

    public function testDebtPeopleSearchRespectsConnectionsAndCoParticipantPrivacy(): void
    {
        $debt = $this->create();
        $this->request('GET', '/api/v1/debt-people?q='.rawurlencode($this->users[2].'@example.test'), 0);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->users[2].'@example.test', $this->body()['items'][0]['email']);
        $this->request('GET', '/api/v1/debt-people?q='.rawurlencode($this->users[2].'@example.test'), 1);
        self::assertSame($this->users[2].'@example.test', $this->body()['items'][0]['email']);
        $this->request('GET', '/api/v1/debt-people?q=Financial%20person%202', 1);
        self::assertCount(1, $this->body()['items']);
        self::assertSame($this->users[2], $this->body()['items'][0]['id']);
        self::assertSame($this->users[2].'@example.test', $this->body()['items'][0]['email']);
        $this->request('GET', '/api/v1/debt-people?q=Financial%20person%203', 0);
        self::assertSame([], $this->body()['items']);
        $this->request('GET', '/api/v1/debt-people?q=%25', 0);
        self::assertSame([], $this->body()['items']);
        $this->db->update('app_user', ['is_active' => 0], ['id' => $this->users[2]]);
        $this->request('GET', '/api/v1/debt-people?q=Financial%20person%202', 0);
        self::assertSame([], $this->body()['items']);
        $this->request('GET', '/api/v1/debt-people?q=Financial%20person%202&debtId='.$debt['id'], 0);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->body()['items'][0]['isActive']);
        $this->request('GET', '/api/v1/debt-people?debtId='.$debt['id'], 1);
        $this->failure(404, 'DEBT_NOT_AVAILABLE');
        $this->request('GET', '/api/v1/debt-people?viewerId='.$this->users[1], 0);
        $this->failure(400, 'INVALID_REQUEST');
        $this->request('GET', '/api/v1/debt-people', null);
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
    }

    public function testDebtPeopleSearchPagesAndBindsCursorToQueryAndViewer(): void
    {
        for ($n = 0; $n < 8; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Page person '.$n, 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00']);
            $pair = [$this->users[0], $id];
            sort($pair);
            $this->db->insert('connect', ['id' => Uuid::v4()->toRfc4122(), 'first_user_id' => $pair[0], 'second_user_id' => $pair[1], 'created_at' => '2026-10-01 00:00:00+00']);
        }
        $this->request('GET', '/api/v1/debt-people?q=Page', 0);
        self::assertResponseStatusCodeSame(200);
        $first = $this->body();
        self::assertCount(5, $first['items']);
        self::assertTrue($first['pagination']['hasMore']);
        $cursor = $first['pagination']['nextCursor'];
        $this->request('GET', '/api/v1/debt-people?q=Page&cursor='.rawurlencode($cursor), 0);
        self::assertResponseStatusCodeSame(200);
        $second = $this->body();
        self::assertCount(3, $second['items']);
        self::assertFalse($second['pagination']['hasMore']);
        self::assertCount(8, array_unique(array_column([...$first['items'], ...$second['items']], 'id')));
        $this->request('GET', '/api/v1/debt-people?q=other&cursor='.rawurlencode($cursor), 0);
        $this->failure(400, 'INVALID_REQUEST');
        $this->request('GET', '/api/v1/debt-people?q=Page&cursor='.rawurlencode($cursor), 1);
        $this->failure(400, 'INVALID_REQUEST');
    }

    public function testDebtPeopleSearchTreatsWildcardsLiterallyAndValidatesParameters(): void
    {
        $this->db->update('app_user', ['name' => 'A_1% Person'], ['id' => $this->users[1]]);
        foreach (['_', '%', 'A_1%'] as $query) {
            $this->request('GET', '/api/v1/debt-people?q='.rawurlencode($query), 0);
            self::assertResponseStatusCodeSame(200);
            self::assertSame([$this->users[1]], array_column($this->body()['items'], 'id'));
        }
        $this->request('GET', '/api/v1/debt-people?q='.rawurlencode(str_repeat('я', 100)), 0);
        self::assertResponseStatusCodeSame(200);
        $this->request('GET', '/api/v1/debt-people?q='.rawurlencode(str_repeat('я', 101)), 0);
        $this->failure(400, 'INVALID_REQUEST');
        foreach (['cursor=%21', 'cursor='.str_repeat('a', 1025), 'q%5B%5D=x', 'debtId=not-a-uuid'] as $query) {
            $this->request('GET', '/api/v1/debt-people?'.$query, 0);
            self::assertResponseStatusCodeSame('debtId=not-a-uuid' === $query ? 404 : 400);
            self::assertSame('debtId=not-a-uuid' === $query ? 'DEBT_NOT_AVAILABLE' : 'INVALID_REQUEST', $this->body()['error']['code']);
        }
    }

    public function testDebtPeopleSearchDoesNotRepeatIdenticallyNamedPeopleAcrossPages(): void
    {
        for ($n = 0; $n < 7; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Same name', 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00']);
            $pair = [$this->users[0], $id];
            sort($pair);
            $this->db->insert('connect', ['id' => Uuid::v4()->toRfc4122(), 'first_user_id' => $pair[0], 'second_user_id' => $pair[1], 'created_at' => '2026-10-01 00:00:00+00']);
        }
        $this->request('GET', '/api/v1/debt-people?q=same%20name', 0);
        self::assertResponseStatusCodeSame(200);
        $first = $this->body();
        self::assertCount(5, $first['items']);
        $this->request('GET', '/api/v1/debt-people?q=same%20name&cursor='.rawurlencode($first['pagination']['nextCursor']), 0);
        self::assertResponseStatusCodeSame(200);
        $second = $this->body();
        self::assertCount(2, $second['items']);
        self::assertFalse($second['pagination']['hasMore']);
        self::assertCount(7, array_unique(array_column([...$first['items'], ...$second['items']], 'id')));
    }

    public function testBalanceSummaryNetsEachPairBeforeSummingAndIncludesInactiveCounterparties(): void
    {
        $this->create(); // Two automatic shares of 334 cents, both owed to viewer 0.
        $this->manual($this->users[1], $this->users[0], 634); // Overpayment: viewer now owes 300 to user 1.
        $this->manual($this->users[2], $this->users[3], 999); // Foreign pair must not affect viewer 0.
        $this->manual($this->users[0], $this->users[1], 100000, true);
        $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$this->users[2]]);
        $this->request('GET', '/api/v1/transfers/balance-summary');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['currency' => 'EUR', 'totalOwedAmount' => 300, 'totalReceivableAmount' => 334], $this->body());
        $this->assertNoForeignEmails($this->body(), 0);
        $this->request('GET', '/api/v1/transfers/balance-summary', 1);
        self::assertSame(['currency' => 'EUR', 'totalOwedAmount' => 0, 'totalReceivableAmount' => 300], $this->body());
        $this->manual($this->users[0], $this->users[1], 300); // Exactly settled pair contributes neither side.
        $this->db->executeStatement('UPDATE transfer SET is_deleted=true WHERE receiver_id=? AND type=?', [$this->users[2], 'DEBT_SHARE']);
        $this->request('GET', '/api/v1/transfers/balance-summary');
        self::assertSame(['currency' => 'EUR', 'totalOwedAmount' => 0, 'totalReceivableAmount' => 0], $this->body());
    }

    public function testBalanceSummaryKeepsOppositeConnectionsSeparate(): void
    {
        $this->manual($this->users[2], $this->users[0], 1000);
        $this->manual($this->users[0], $this->users[1], 700);

        $this->request('GET', '/api/v1/transfers/balance-summary');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['currency' => 'EUR', 'totalOwedAmount' => 1000, 'totalReceivableAmount' => 700], $this->body());
    }

    public function testBalanceSummaryIncludesEveryConnectionAndUsesWideIntegerTotals(): void
    {
        for ($n = 0; $n < 25; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Counterparty', 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00']);
            $this->manual($this->users[0], $id, 2147483647);
        }
        $this->request('GET', '/api/v1/transfers/balance-summary');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['currency' => 'EUR', 'totalOwedAmount' => 0, 'totalReceivableAmount' => 25 * 2147483647], $this->body());
    }

    public function testHomeExpenseLimitOrdersByCreationAndIncludesPayerOutsideSplit(): void
    {
        $ids = [];
        for ($n = 0; $n < 7; ++$n) {
            $command = [...$this->command(), 'title' => 'Expense '.$n, 'includePayerInSplit' => false];
            $this->request('POST', '/api/v1/debts', 0, $command, Uuid::v4()->toRfc4122());
            self::assertResponseStatusCodeSame(201);
            $ids[] = $this->body()['id'];
        }
        // Same timestamps exercise the stable id tie breaker; edits never affect order.
        $this->db->executeStatement('UPDATE debt SET created_at=?,version=2', ['2026-10-18 10:00:00+00']);
        rsort($ids);
        $this->db->executeStatement('UPDATE debt SET is_deleted=true WHERE id=?', [$ids[0]]);
        foreach ([0, 1] as $viewer) {
            $this->request('GET', '/api/v1/debts?limit=5&status=active', $viewer);
            self::assertResponseStatusCodeSame(200);
            self::assertSame(array_slice($ids, 1, 5), array_column($this->body()['items'], 'id'));
        }
        $this->db->executeStatement('UPDATE debt_participant SET is_deleted=true WHERE user_id=?', [$this->users[1]]);
        foreach ([1, 3] as $viewer) {
            $this->request('GET', '/api/v1/debts?limit=5&status=active', $viewer);
            self::assertSame([], $this->body()['items']);
        }
    }

    public function testUserTransferHistoryFiltersDirectionPaginatesAndHidesForeignOperations(): void
    {
        $this->manual($this->users[1], $this->users[0], 100);
        $this->manual($this->users[2], $this->users[0], 200, true);
        $this->manual($this->users[0], $this->users[1], 300);
        $this->manual($this->users[2], $this->users[3], 999);

        $this->request('GET', '/api/v1/transfers?direction=incoming&limit=1');
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $first = $this->body();
        self::assertCount(1, $first['items']);
        self::assertTrue($first['pagination']['hasMore']);
        $this->request('GET', '/api/v1/transfers?direction=incoming&limit=1&cursor='.$first['pagination']['nextCursor']);
        $second = $this->body();
        self::assertCount(1, $second['items']);
        self::assertFalse($second['pagination']['hasMore']);
        self::assertNotSame($first['items'][0]['id'], $second['items'][0]['id']);
        $incoming = [$first['items'][0], $second['items'][0]];
        $amounts = array_column($incoming, 'amount');
        sort($amounts);
        self::assertSame([100, 200], $amounts);
        self::assertCount(1, array_filter($incoming, fn (array $item): bool => $item['isDeleted']));
        $this->assertNoForeignEmails($this->body(), 0);
        $this->request('GET', '/api/v1/transfers?direction=outgoing');
        self::assertSame([300], array_column($this->body()['items'], 'amount'));
        foreach (['', '?direction=invalid', '?direction=incoming&limit=101', '?direction=incoming&viewerId='.$this->users[1], '?direction=incoming&cursor=invalid'] as $query) {
            $this->request('GET', '/api/v1/transfers'.$query);
            $this->failure(400, 'INVALID_REQUEST');
        }
        $this->request('GET', '/api/v1/transfers?direction=incoming', null);
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
    }

    private function manual(string $sender, string $receiver, int $amount, bool $deleted = false): void
    {
        $pair = [$sender, $receiver];
        sort($pair);
        $connect = $this->db->fetchOne('SELECT id FROM connect WHERE first_user_id=? AND second_user_id=?', $pair);
        if (false === $connect) {
            $connect = Uuid::v4()->toRfc4122();
            $this->db->insert('connect', ['id' => $connect, 'first_user_id' => $pair[0], 'second_user_id' => $pair[1], 'created_at' => '2026-10-18 00:00:00+00']);
        }
        $this->db->insert('transfer', ['id' => Uuid::v4()->toRfc4122(), 'connect_id' => $connect, 'sender_id' => $sender, 'receiver_id' => $receiver, 'amount' => $amount, 'type' => 'MANUAL', 'created_by_id' => $sender, 'created_at' => '2026-10-18 00:00:00+00', 'is_deleted' => $deleted, 'version' => 1], ['is_deleted' => 'boolean']);
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
        $connect01 = (string) $this->db->fetchOne('SELECT id FROM connect WHERE first_user_id IN (?,?) AND second_user_id IN (?,?)', [$this->users[0], $this->users[1], $this->users[0], $this->users[1]]);
        $connect02 = (string) $this->db->fetchOne('SELECT id FROM connect WHERE first_user_id IN (?,?) AND second_user_id IN (?,?)', [$this->users[0], $this->users[2], $this->users[0], $this->users[2]]);
        $connect12 = (string) $this->db->fetchOne('SELECT id FROM connect WHERE first_user_id IN (?,?) AND second_user_id IN (?,?)', [$this->users[1], $this->users[2], $this->users[1], $this->users[2]]);
        foreach ([0, 1, 2] as $viewer) {
            $this->request('GET', '/api/v1/debts/'.$debt['id'], $viewer);
            self::assertResponseStatusCodeSame(200);
            $detail = $this->body();
            self::assertSame(0 === $viewer, $detail['canEdit']);
            $participants = [];
            foreach ($detail['participants'] as $participant) {
                $participants[$participant['user']['id']] = $participant;
            }
            $expectedConnects = match ($viewer) {
                0 => [$this->users[0] => null, $this->users[1] => $connect01, $this->users[2] => $connect02],
                1 => [$this->users[0] => $connect01, $this->users[1] => null, $this->users[2] => $connect12],
                2 => [$this->users[0] => $connect02, $this->users[1] => $connect12, $this->users[2] => null],
            };
            self::assertEquals($expectedConnects, array_map(static fn (array $participant): ?string => $participant['connectId'], $participants));
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

    public function testIdempotencyKeyIsScopedByUserAndOperation(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $command = $this->command();

        $this->request('POST', '/api/v1/debts', 0, $command, $key);
        self::assertResponseStatusCodeSame(201);
        $firstDebt = $this->body();

        // The same key and operation are independent in another user's scope.
        $this->request('POST', '/api/v1/debts', 1, $command, $key);
        self::assertResponseStatusCodeSame(201);
        $otherUserDebt = $this->body();
        self::assertNotSame($firstDebt['id'], $otherUserDebt['id']);

        // The same key in the same user scope is also independent for a new operation.
        $connect = (string) $this->db->fetchOne(
            'SELECT id FROM connect WHERE (first_user_id=? AND second_user_id=?) OR (first_user_id=? AND second_user_id=?)',
            [$this->users[0], $this->users[1], $this->users[1], $this->users[0]],
        );
        $this->request('POST', '/api/v1/connects/'.$connect.'/transfers', 0, [
            'senderId' => $this->users[1],
            'receiverId' => $this->users[0],
            'amount' => 100,
        ], $key);
        self::assertResponseStatusCodeSame(201);
        $manualTransfer = $this->body();

        // Replaying the original operation still returns its own saved result.
        $this->request('POST', '/api/v1/debts', 0, $command, $key);
        self::assertResponseStatusCodeSame(201);
        $replayedDebt = $this->body();
        self::assertEquals($firstDebt, $replayedDebt);
        self::assertSame($firstDebt['id'], $replayedDebt['id']);
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM idempotency_record WHERE idempotency_key=?', [$key]));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt'));
        self::assertSame($this->users[0], $manualTransfer['createdBy']['id']);
    }

    public function testForeignEmailsAreAbsentFromEveryNestedFinancialReadModel(): void
    {
        $debt = $this->create();
        $participant = array_values(array_filter($debt['participants'], fn (array $p): bool => $p['user']['id'] === $this->users[1]))[0];
        $transferId = $participant['transferId'];
        $transferConnectId = (string) $this->db->fetchOne('SELECT connect_id FROM transfer WHERE id=?', [$transferId]);

        // Debt creation also returns the full nested debt representation.
        $this->assertNoForeignEmails($debt, 0);

        foreach ([0, 1, 2] as $viewer) {
            $this->request('GET', '/api/v1/connects', $viewer);
            self::assertResponseStatusCodeSame(200);
            $this->assertNoForeignEmails($this->body(), $viewer);

            $connectIds = $this->db->fetchFirstColumn(
                'SELECT id FROM connect WHERE first_user_id=? OR second_user_id=?',
                [$this->users[$viewer], $this->users[$viewer]],
            );
            foreach ($connectIds as $connectId) {
                $this->request('GET', '/api/v1/connects/'.$connectId, $viewer);
                self::assertResponseStatusCodeSame(200);
                $this->assertNoForeignEmails($this->body(), $viewer);
            }

            $this->request('GET', '/api/v1/debts', $viewer);
            self::assertResponseStatusCodeSame(200);
            $this->assertNoForeignEmails($this->body(), $viewer);

            $this->request('GET', '/api/v1/debts/'.$debt['id'], $viewer);
            self::assertResponseStatusCodeSame(200);
            $this->assertNoForeignEmails($this->body(), $viewer);
        }

        foreach ([0, 1] as $viewer) {
            $this->request('GET', '/api/v1/transfers/'.$transferId, $viewer);
            self::assertResponseStatusCodeSame(200);
            $this->assertNoForeignEmails($this->body(), $viewer);

            $this->request('GET', '/api/v1/connects/'.$transferConnectId.'/transfers', $viewer);
            self::assertResponseStatusCodeSame(200);
            $this->assertNoForeignEmails($this->body(), $viewer);
        }
    }

    public function testDebtCreationRejectsInactivePayerOrParticipantWithoutPartialWrites(): void
    {
        /** @var array<string, array{int, array{payerId: string, participantIds: list<string>}}> $cases */
        $cases = [
            'payer' => [1, ['payerId' => $this->users[1], 'participantIds' => [$this->users[0], $this->users[2]]]],
            'participant' => [2, ['payerId' => $this->users[0], 'participantIds' => [$this->users[1], $this->users[2]]]],
        ];
        foreach ($cases as $role => [$inactiveUser, $overrides]) {
            $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$this->users[$inactiveUser]]);
            $command = [...$this->command(), ...$overrides];
            $before = [
                'debts' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt'),
                'participants' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt_participant'),
                'transfers' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM transfer'),
                'connects' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect'),
            ];
            $this->request('POST', '/api/v1/debts', 0, $command, Uuid::v4()->toRfc4122());
            $this->failure(409, 'INACTIVE_USER');
            self::assertSame($before, [
                'debts' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt'),
                'participants' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt_participant'),
                'transfers' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM transfer'),
                'connects' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect'),
            ], 'Rejected '.$role.' inactivity must leave no financial rows behind.');
            $this->db->executeStatement('UPDATE app_user SET is_active=true WHERE id=?', [$this->users[$inactiveUser]]);
        }
    }

    public function testInactiveBearerCannotIssueAnyImplementedProtectedCommand(): void
    {
        $debt = $this->create();
        $transfer = $debt['participants'][1]['transferId'];
        $connect = (string) $this->db->fetchOne('SELECT connect_id FROM transfer WHERE id=?', [$transfer]);
        $this->request('POST', '/api/v1/connect-invitations', 0);
        self::assertResponseStatusCodeSame(200);
        $invitationToken = $this->body()['token'];
        $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$this->users[0]]);

        /** @var list<array{string, string, ?array<string, mixed>, ?string, ?string}> $commands */
        $commands = [
            ['GET', '/api/v1/transfers/balance-summary', null, null, null],
            ['POST', '/api/v1/auth/logout-all', null, null, null],
            ['POST', '/api/v1/account/password-change', ['currentPassword' => 'test-only', 'newPassword' => 'New secure password 123!'], null, null],
            ['POST', '/api/v1/connect-invitations', null, null, null],
            ['POST', '/api/v1/connect-invitations/'.$invitationToken.'/accept', null, null, null],
            ['POST', '/api/v1/debts', $this->command(), Uuid::v4()->toRfc4122(), null],
            ['PUT', '/api/v1/debts/'.$debt['id'], $this->command(), null, '"1"'],
            ['POST', '/api/v1/connects/'.$connect.'/transfers', ['senderId' => $this->users[0], 'receiverId' => $this->users[1], 'amount' => 100], Uuid::v4()->toRfc4122(), null],
        ];

        foreach ($commands as [$method, $path, $body, $key, $etag]) {
            $this->request($method, $path, 0, $body, $key, $etag);
            $this->failure(401, 'AUTHENTICATION_REQUIRED');
        }

        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect_invitation WHERE created_by_id=? AND used_at IS NULL', [$this->users[0]]));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM transfer'));
        self::assertSame('Financial person 0', $this->db->fetchOne('SELECT name FROM app_user WHERE id=?', [$this->users[0]]));
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
        self::assertSame($debt['id'], $this->body()['items'][0]['id']);
        self::assertTrue($this->body()['items'][0]['isDeleted']);
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
