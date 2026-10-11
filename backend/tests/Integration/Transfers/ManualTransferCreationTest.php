<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transfers;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\CreateManualTransfer;
use App\Transfers\Application\CreateManualTransferCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ManualTransferCreationTest extends KernelTestCase
{
    private Connection $db;
    private CreateManualTransfer $service;
    /** @var list<string> */ private array $users = [];
    private string $connect;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->db = $c->get(Connection::class);
        $this->service = $c->get('test.create_manual_transfer');
        $this->db->beginTransaction();
        for ($n = 0; $n < 3; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->users[] = $id;
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Manual user', 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => '2026-10-11 12:00:00+00']);
        }
        $this->connect = Uuid::v4()->toRfc4122();
        $pair = [$this->users[0], $this->users[1]];
        sort($pair);
        $this->db->insert('connect', ['id' => $this->connect, 'first_user_id' => $pair[0], 'second_user_id' => $pair[1], 'created_at' => '2026-10-11 12:00:00+00']);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function command(string $key, int $amount = 700): CreateManualTransferCommand
    {
        return new CreateManualTransferCommand($this->connect, $this->users[1], $this->users[0], $amount, $key);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $rows = [];
        foreach (['transfer', 'idempotency_record', 'debt', 'debt_participant', 'connect'] as $table) {
            $rows[$table] = (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$table);
        }

        return $rows;
    }

    public function testBothDirectionsOverpaymentAndOriginalReplayWithoutDebtMutation(): void
    {
        $before = $this->counts();
        $key = Uuid::v4()->toRfc4122();
        $first = $this->service->handle($this->command($key), $this->users[0]);
        self::assertSame(201, $first['status']);
        self::assertSame('MANUAL', $first['body']['type']);
        self::assertSame($this->users[0], $first['body']['createdBy']['id']);
        self::assertNull($first['body']['debtReference']);
        self::assertNull($first['body']['relatedTransferId']);
        $this->db->executeStatement('UPDATE app_user SET name=? WHERE id=?', ['Renamed', $this->users[1]]);
        self::assertSame($first, $this->service->handle($this->command($key), $this->users[0]));
        $second = new CreateManualTransferCommand($this->connect, $this->users[0], $this->users[1], 1001, Uuid::v4()->toRfc4122());
        $this->service->handle($second, $this->users[1]);
        $view = self::getContainer()->get('test.connect_service')->detail($this->connect, $this->users[0]);
        self::assertSame(301, $view['balanceAmount']);
        self::assertSame(-301, self::getContainer()->get('test.connect_service')->detail($this->connect, $this->users[1])['balanceAmount']);
        $after = $this->counts();
        foreach (['debt', 'debt_participant', 'connect'] as $table) {
            self::assertSame($before[$table], $after[$table]);
        }
        self::assertSame($before['transfer'] + 2, $after['transfer']);
        self::assertSame($before['idempotency_record'] + 2, $after['idempotency_record']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM transfer WHERE connect_id=? AND (debt_id IS NOT NULL OR debt_participant_id IS NOT NULL OR related_transfer_id IS NOT NULL)', [$this->connect]));
    }

    public function testChangedBodyConflictsWithoutWrite(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $first = $this->service->handle($this->command($key), $this->users[0]);
        $before = $this->counts();
        try {
            $this->service->handle($this->command($key, 701), $this->users[0]);
            self::fail('Expected conflict.');
        } catch (ApplicationFailure $e) {
            self::assertSame(ApplicationFailureCode::IdempotencyKeyReused, $e->failureCode);
        }
        self::assertSame($before, $this->counts());
    }

    public function testExpiryAllowsNewCommandBeforePhysicalCleanup(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $first = $this->service->handle($this->command($key), $this->users[0]);
        $this->db->executeStatement("UPDATE idempotency_record SET created_at=now()-interval '25 hours', expires_at=now()-interval '1 hour' WHERE idempotency_key=?", [$key]);
        self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->clear();
        $next = $this->service->handle($this->command($key), $this->users[0]);
        self::assertNotSame($first['body']['id'], $next['body']['id']);
    }

    #[DataProvider('denials')]
    public function testAclSidesAndFreshActiveAccounts(string $case, ApplicationFailureCode $code): void
    {
        $author = 'foreign' === $case ? $this->users[2] : $this->users[0];
        $connect = 'missing' === $case ? Uuid::v4()->toRfc4122() : $this->connect;
        $receiver = 'wrong side' === $case ? $this->users[2] : $this->users[0];
        if ('inactive author' === $case || 'inactive other' === $case) {
            $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$this->users['inactive author' === $case ? 0 : 1]]);
        }
        $before = $this->counts();
        try {
            $this->service->handle(new CreateManualTransferCommand($connect, $this->users[1], $receiver, 100, Uuid::v4()->toRfc4122()), $author);
            self::fail('Expected denial.');
        } catch (ApplicationFailure $e) {
            self::assertSame($code, $e->failureCode);
        }
        self::assertSame($before, $this->counts());
    }

    /** @return iterable<array{string,ApplicationFailureCode}> */
    public static function denials(): iterable
    {
        yield ['foreign', ApplicationFailureCode::ResourceNotFound];
        yield ['missing', ApplicationFailureCode::ResourceNotFound];
        yield ['wrong side', ApplicationFailureCode::ValidationError];
        yield ['inactive author', ApplicationFailureCode::InactiveUser];
        yield ['inactive other', ApplicationFailureCode::InactiveUser];
    }

    #[DataProvider('failureStages')]
    public function testFailureRollsBackTransferAndKey(string $table, string $event): void
    {
        $key = Uuid::v4()->toRfc4122();
        if ('UPDATE' === $event) {
            $this->service->handle($this->command($key), $this->users[0]);
            $this->db->executeStatement("UPDATE idempotency_record SET created_at=now()-interval '25 hours', expires_at=now()-interval '1 hour' WHERE idempotency_key=?", [$key]);
            self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->clear();
        }
        $before = $this->counts();
        $this->db->executeStatement("CREATE FUNCTION manual_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''controlled test failure''; END;'");
        $this->db->executeStatement('CREATE TRIGGER manual_test_failure BEFORE '.$event.' ON '.$table.' FOR EACH ROW EXECUTE FUNCTION manual_test_failure()');
        try {
            $this->service->handle($this->command($key), $this->users[0]);
            self::fail('Expected rollback.');
        } catch (DriverException) {
        }
        self::assertSame($before, $this->counts());
    }

    /** @return iterable<array{string,string}> */
    public static function failureStages(): iterable
    {
        yield ['idempotency_record', 'INSERT'];
        yield ['transfer', 'INSERT'];
        yield ['idempotency_record', 'UPDATE'];
    }
}
