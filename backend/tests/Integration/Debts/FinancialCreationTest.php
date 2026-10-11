<?php

declare(strict_types=1);

namespace App\Tests\Integration\Debts;

use App\Debts\Application\CreateDebt;
use App\Debts\Application\CreateDebtCommand;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class FinancialCreationTest extends KernelTestCase
{
    private Connection $db;
    private CreateDebt $service;
    /** @var list<string> */ private array $users = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->db = $c->get(Connection::class);
        $this->service = $c->get('test.create_debt');
        $this->db->beginTransaction();
        for ($n = 0; $n < 4; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->users[] = $id;
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Finance user '.$n, 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => true, 'created_at' => '2026-10-11 12:00:00+00'], ['is_active' => 'boolean']);
        }
        $this->connect($this->users[0], $this->users[1]);
        $this->connect($this->users[0], $this->users[2]);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        } self::getContainer()->get(EntityManagerInterface::class)->clear();
        parent::tearDown();
    }

    private function connect(string $a, string $b): void
    {
        $pair = [$a, $b];
        sort($pair);
        $this->db->insert('connect', ['id' => Uuid::v4()->toRfc4122(), 'first_user_id' => $pair[0], 'second_user_id' => $pair[1], 'created_at' => '2026-10-11 12:00:00+00']);
    }

    private function command(?string $key = null, int $amount = 1001): CreateDebtCommand
    {
        return new CreateDebtCommand('Dinner', null, $amount, $this->users[1], [$this->users[2], $this->users[0]], true, $key ?? Uuid::v4()->toRfc4122());
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $result = [];
        foreach (['debt', 'debt_participant', 'connect', 'transfer', 'idempotency_record'] as $table) {
            $result[$table] = (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$table);
        }

        return $result;
    }

    public function testAtomicCreationByAuthorDifferentFromPayerAndReplay(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $before = $this->counts();
        $first = $this->service->handle($this->command($key), $this->users[0]);
        self::assertSame(201, $first['status']);
        $body = $first['body'];
        self::assertSame($this->users[0], $body['createdBy']['id']);
        self::assertSame($this->users[1], $body['payer']['id']);
        self::assertSame(3, $body['participantCount']);
        self::assertSame(334, $body['shareAmount']);
        self::assertTrue($body['includePayerInSplit']);
        $after = $this->counts();
        foreach (['debt' => 1, 'debt_participant' => 3, 'connect' => 1, 'transfer' => 2, 'idempotency_record' => 1] as $table => $delta) {
            self::assertSame($before[$table] + $delta, $after[$table]);
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM transfer WHERE sender_id=receiver_id'));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM transfer WHERE debt_id=? AND (created_by_id IS NOT NULL OR type <> 'DEBT_SHARE')", [$body['id']]));
        $normalized = new CreateDebtCommand(' Dinner ', null, 1001, strtoupper($this->users[1]), [$this->users[0], $this->users[2]], true, strtoupper($key));
        self::assertSame($first, $this->service->handle($normalized, $this->users[0]));
        self::assertSame($after, $this->counts());
        $privacy = json_encode($body, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('email', $privacy);
        self::assertStringNotContainsString('hash', $privacy);
        self::assertSame('user:'.$this->users[0], $this->db->fetchOne('SELECT scope FROM idempotency_record WHERE idempotency_key=?', [$key]));
        self::assertSame($this->users[0], $this->db->fetchOne('SELECT user_id FROM idempotency_record WHERE idempotency_key=?', [$key]));
    }

    public function testChangedCommandWithSameKeyConflictsWithoutWrite(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $this->service->handle($this->command($key), $this->users[0]);
        $before = $this->counts();
        try {
            $this->service->handle($this->command($key, 2001), $this->users[0]);
            self::fail('Expected conflict.');
        } catch (ApplicationFailure $e) {
            self::assertSame(ApplicationFailureCode::IdempotencyKeyReused, $e->failureCode);
        }
        self::assertSame($before, $this->counts());
    }

    public function testExpiredKeyRestartsAfterLogicalDayEvenBeforeCleanup(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $first = $this->service->handle($this->command($key), $this->users[0]);
        $this->db->executeStatement("UPDATE idempotency_record SET created_at=now()-interval '25 hours', expires_at=now()-interval '1 hour' WHERE idempotency_key=?", [$key]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $next = $this->service->handle($this->command($key), $this->users[0]);
        self::assertNotSame($first['body']['id'], $next['body']['id']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM idempotency_record WHERE idempotency_key=?', [$key]));
    }

    public function testCurrentCoParticipantIsEligibleWithoutExistingPair(): void
    {
        $a = $this->users[0];
        $b = $this->users[1];
        $c = $this->users[2];
        $this->service->handle(new CreateDebtCommand('Shared', null, 1000, $a, [$b, $c], true, Uuid::v4()->toRfc4122()), $a);
        $result = $this->service->handle(new CreateDebtCommand('Next', null, 500, $c, [$b], false, Uuid::v4()->toRfc4122()), $b);
        self::assertSame($c, $result['body']['payer']['id']);
        self::assertSame(500, $result['body']['shareAmount']);
        self::assertFalse($result['body']['includePayerInSplit']);
        self::assertSame(1, $result['body']['participantCount']);
    }

    #[DataProvider('denials')]
    public function testInvalidSelectionsOrInactiveUsersHaveNoPartialEffect(string $case, ApplicationFailureCode $code): void
    {
        $a = $this->users[0];
        $b = $this->users[1];
        $other = $this->users[3];
        if ('inactive' === $case || 'inactive author' === $case) {
            $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', ['inactive author' === $case ? $a : $b]);
        }
        $payer = 'unavailable' === $case ? $other : ('unknown' === $case ? '00000000-0000-4000-8000-000000000099' : $b);
        $parts = 'missing author' === $case ? [$this->users[2]] : [$a];
        $before = $this->counts();
        try {
            $this->service->handle(new CreateDebtCommand('Rejected', null, 100, $payer, $parts, false, Uuid::v4()->toRfc4122()), $a);
            self::fail('Expected denial.');
        } catch (ApplicationFailure $e) {
            self::assertSame($code, $e->failureCode);
        }
        self::assertSame($before, $this->counts());
    }

    /** @return iterable<array{string,ApplicationFailureCode}> */
    public static function denials(): iterable
    {
        yield ['unavailable', ApplicationFailureCode::InvalidParticipant];
        yield ['unknown', ApplicationFailureCode::InvalidParticipant];
        yield ['inactive', ApplicationFailureCode::InactiveUser];
        yield ['inactive author', ApplicationFailureCode::InactiveUser];
        yield ['missing author', ApplicationFailureCode::InvalidParticipant];
    }

    #[DataProvider('failureStages')]
    public function testDatabaseFailureAtEveryCreationStageRollsBackAggregateAndKey(string $table): void
    {
        $before = $this->counts();
        $this->db->executeStatement("CREATE FUNCTION financial_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''controlled test failure''; END;'");
        $this->db->executeStatement('CREATE TRIGGER financial_test_failure BEFORE INSERT ON '.$table.' FOR EACH ROW EXECUTE FUNCTION financial_test_failure()');
        try {
            $this->service->handle($this->command(), $this->users[0]);
            self::fail('Expected rollback.');
        } catch (DriverException) {
        }
        self::assertSame($before, $this->counts());
    }

    /** @return iterable<array{string}> */
    public static function failureStages(): iterable
    {
        foreach (['debt', 'debt_participant', 'connect', 'transfer', 'idempotency_record'] as $table) {
            yield [$table];
        }
    }
}
