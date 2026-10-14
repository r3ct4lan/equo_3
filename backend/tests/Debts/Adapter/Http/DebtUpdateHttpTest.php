<?php

declare(strict_types=1);

namespace App\Tests\Debts\Adapter\Http;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class DebtUpdateHttpTest extends WebTestCase
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

    /** @return array<string,mixed> */
    private function create(): array
    {
        $this->request('POST', '/api/v1/debts', 0, $this->command(), Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(201);

        return $this->body();
    }

    /** @return array<string,mixed> */
    private function updateBody(): array
    {
        return ['title' => 'Updated dinner', 'description' => 'New description', 'totalAmount' => 1201, 'participantIds' => [$this->users[1]], 'includePayerInSplit' => false];
    }

    public function testUpdateExcludesAndRestoresOriginalRowsAndBothReadModels(): void
    {
        $initial = $this->create();
        $excluded = array_values(array_filter($initial['participants'], fn (array $p): bool => $p['user']['id'] === $this->users[2]))[0];
        $originalTransfer = $this->db->fetchAssociative('SELECT * FROM transfer WHERE id=?', [$excluded['transferId']]);
        self::assertIsArray($originalTransfer);
        $path = '/api/v1/debts/'.$initial['id'];
        $command = $this->updateBody();
        $this->request('PUT', $path, 0, $command, etag: '"1"');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('ETag', '"2"');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $updated = $this->body();
        self::assertSame(1201, $updated['shareAmount']);
        self::assertSame(1, $updated['participantCount']);
        self::assertFalse($updated['includePayerInSplit']);
        self::assertSame($this->users[0], $updated['payer']['id']);
        $this->request('GET', $path, 2);
        $this->failure(404, 'DEBT_NOT_AVAILABLE');
        $this->request('GET', '/api/v1/transfers/'.$excluded['transferId'], 2);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->body()['isDeleted']);
        self::assertSame(['id' => $initial['id'], 'isAccessible' => false], $this->body()['debtReference']);
        $this->request('GET', '/api/v1/connects/'.$originalTransfer['connect_id'], 2);
        self::assertSame(0, $this->body()['balanceAmount']);
        $command['participantIds'][] = $this->users[2];
        $command['includePayerInSplit'] = true;
        $this->request('PUT', $path, 0, $command, etag: '"2"');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('ETag', '"3"');
        $restored = $this->body();
        self::assertSame(401, $restored['shareAmount']);
        $restoredP = array_values(array_filter($restored['participants'], fn (array $p): bool => $p['user']['id'] === $this->users[2]))[0];
        self::assertSame($excluded['debtParticipantId'], $restoredP['debtParticipantId']);
        self::assertSame($excluded['transferId'], $restoredP['transferId']);
        self::assertSame($originalTransfer['created_at'], $this->db->fetchOne('SELECT created_at FROM transfer WHERE id=?', [$excluded['transferId']]));
        $this->request('GET', '/api/v1/transfers/'.$excluded['transferId'], 2);
        self::assertFalse($this->body()['isDeleted']);
        self::assertResponseHeaderSame('ETag', '"3"');
        foreach ([0 => 401, 2 => -401] as $viewer => $balance) {
            $this->request('GET', '/api/v1/connects/'.$originalTransfer['connect_id'], $viewer);
            self::assertSame($balance, $this->body()['balanceAmount']);
        }
        $this->request('GET', $path, 2);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(3, $this->body()['participantCount']);
    }

    public function testNoOpKeepsEtagAndStaleUpdateDoesNotOverwrite(): void
    {
        $initial = $this->create();
        $command = $this->command();
        unset($command['payerId']);
        $path = '/api/v1/debts/'.$initial['id'];
        $this->request('GET', $path);
        $read = $this->body();
        $this->request('PUT', $path, 0, $command, etag: '"1"');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('ETag', '"1"');
        self::assertEquals($read, $this->body());
        $changed = $this->updateBody();
        $this->request('PUT', $path, 0, $changed, etag: '"1"');
        self::assertResponseStatusCodeSame(200);
        $this->request('PUT', $path, 0, $command, etag: '"1"');
        $this->failure(409, 'VERSION_CONFLICT');
        self::assertSame(['expectedVersion' => 1, 'actualVersion' => 2], $this->body()['error']['details']);
        $this->request('GET', $path);
        self::assertSame(1201, $this->body()['totalAmount']);
        self::assertSame(2, $this->body()['version']);
    }

    public function testAclFrozenDeletedAndAuthorCannotExcludeSelf(): void
    {
        $initial = $this->create();
        $path = '/api/v1/debts/'.$initial['id'];
        $this->request('PUT', $path, 1, $this->updateBody(), etag: '"1"');
        $this->failure(403, 'FORBIDDEN');
        $hiddenDebtError = null;
        foreach ([$path, '/api/v1/debts/'.Uuid::v4()->toRfc4122(), '/api/v1/debts/invalid'] as $route) {
            $this->request('PUT', $route, 3, $this->updateBody(), etag: '"99"');
            $this->failure(404, 'DEBT_NOT_AVAILABLE');
            $error = $this->body()['error'];
            self::assertArrayNotHasKey('details', $error);
            if (null !== $hiddenDebtError) {
                self::assertSame($hiddenDebtError['code'], $error['code']);
                self::assertSame($hiddenDebtError['message'], $error['message']);
            }
            $hiddenDebtError = $error;
            self::assertStringNotContainsString('Dinner', (string) $this->client->getResponse()->getContent());
        }
        $otherPayer = $this->command();
        $otherPayer['payerId'] = $this->users[1];
        $otherPayer['participantIds'] = [$this->users[0], $this->users[2]];
        $this->request('POST', '/api/v1/debts', 0, $otherPayer, Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(201);
        $other = $this->body();
        $invalid = $this->updateBody();
        $invalid['participantIds'] = [$this->users[2]];
        $this->request('PUT', '/api/v1/debts/'.$other['id'], 0, $invalid, etag: '"1"');
        $this->failure(422, 'INVALID_PARTICIPANT');
        $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$this->users[1]]);
        $this->request('PUT', '/api/v1/debts/'.$other['id'], 0, $invalid, etag: '"1"');
        $this->failure(409, 'DEBT_FROZEN');
        $this->db->executeStatement('UPDATE debt SET is_deleted=true WHERE id=?', [$initial['id']]);
        $this->request('PUT', $path, 0, $this->updateBody(), etag: '"1"');
        $this->failure(409, 'DEBT_DELETED');
    }

    #[DataProvider('badHeaders')]
    public function testStrictRequiredIfMatch(?string $etag, int $status, string $code): void
    {
        $initial = $this->create();
        $this->request('PUT', '/api/v1/debts/'.$initial['id'], 0, $this->updateBody(), etag: $etag);
        $this->failure($status, $code);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT version FROM debt WHERE id=?', [$initial['id']]));
    }

    /** @return iterable<array{?string,int,string}> */
    public static function badHeaders(): iterable
    {
        yield [null, 428, 'PRECONDITION_REQUIRED'];
        foreach (['1', 'W/"1"', '*', '"1","2"', '"0"', '"01"', '"2147483648"', '"1" garbage'] as $header) {
            yield [$header, 400, 'INVALID_REQUEST'];
        }
    }

    #[DataProvider('badBody')]
    public function testInvalidFieldsAndTypesDoNotMutate(string $field, mixed $value, int $status): void
    {
        $initial = $this->create();
        $command = $this->updateBody();
        $command[$field] = $value;
        $this->request('PUT', '/api/v1/debts/'.$initial['id'], 0, $command, etag: '"1"');
        $this->failure($status, 400 === $status ? 'INVALID_REQUEST' : 'VALIDATION_ERROR');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT version FROM debt WHERE id=?', [$initial['id']]));
    }

    /** @return iterable<array{string,mixed,int}> */
    public static function badBody(): iterable
    {
        foreach ([0, -1, 2147483648, 1.5, '100', true, null] as $amount) {
            yield ['totalAmount', $amount, 422];
        }
        yield ['payerId', 'immutable', 400];
        yield ['version', 1, 400];
        yield ['title', '  ', 422];
        yield ['participantIds', [], 422];
        yield ['participantIds', [7], 422];
        yield ['includePayerInSplit', 'true', 422];
    }

    public function testCurrentInactiveRemainsCountedAndInactiveRestorationFails(): void
    {
        $initial = $this->create();
        $path = '/api/v1/debts/'.$initial['id'];
        $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$this->users[2]]);
        $body = $this->command();
        unset($body['payerId']);
        $body['totalAmount'] = 1201;
        $this->request('PUT', $path, 0, $body, etag: '"1"');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(3, $this->body()['participantCount']);
        self::assertSame(401, $this->body()['shareAmount']);
        $removed = [...$body, 'participantIds' => [$this->users[1]]];
        $this->request('PUT', $path, 0, $removed, etag: '"2"');
        self::assertResponseStatusCodeSame(200);
        $this->request('PUT', $path, 0, $body, etag: '"3"');
        $this->failure(409, 'INACTIVE_USER');
        $this->request('PUT', $path, null, $body, etag: '"3"');
        $this->failure(401, 'AUTHENTICATION_REQUIRED');
    }
}
