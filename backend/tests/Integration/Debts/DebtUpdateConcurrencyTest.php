<?php

declare(strict_types=1);

namespace App\Tests\Integration\Debts;

use App\Debts\Application\CreateDebtCommand;
use App\Tests\Fixture\Concurrency\DebtUpdateRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

final class DebtUpdateConcurrencyTest extends KernelTestCase
{
    private Connection $db;
    /** @var list<string> */ private array $users = [];
    private string $debtId;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->db = $c->get(Connection::class);
        for ($n = 0; $n < 3; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->users[] = $id;
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Concurrent update', 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => '2026-10-12 12:00:00+00']);
        }
        foreach ([1, 2] as $n) {
            $pair = [$this->users[0], $this->users[$n]];
            sort($pair);
            $this->db->insert('connect', ['id' => Uuid::v4()->toRfc4122(), 'first_user_id' => $pair[0], 'second_user_id' => $pair[1], 'created_at' => '2026-10-12 12:00:00+00']);
        }
        $this->debtId = $c->get('test.create_debt')->handle(new CreateDebtCommand('Dinner', null, 1001, $this->users[1], [$this->users[0], $this->users[2]], true, Uuid::v4()->toRfc4122()), $this->users[0])['body']['id'];
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        $this->db->delete('transfer', ['debt_id' => $this->debtId]);
        $this->db->delete('debt_participant', ['debt_id' => $this->debtId]);
        $this->db->delete('debt', ['id' => $this->debtId]);
        $this->db->delete('idempotency_record', ['user_id' => $this->users[0]]);
        foreach ($this->users as $id) {
            $this->db->executeStatement('DELETE FROM connect WHERE first_user_id=? OR second_user_id=?', [$id, $id]);
            $this->db->delete('app_user', ['id' => $id]);
        }
        parent::tearDown();
    }

    #[DataProvider('races')]
    public function testRootLockSerializesRealWritersAndFreshActivity(string $race): void
    {
        $c = self::getContainer();
        self::assertInstanceOf(TestContainer::class, $c);
        $first = ['author' => $this->users[0], 'debt' => $this->debtId, 'other' => $this->users[2], 'amount' => 1201, 'version' => 1];
        $second = [...$first, 'amount' => 1401, 'version' => in_array($race, ['payer deactivation', 'current deactivation'], true) ? 2 : 1];
        $this->db->beginTransaction();
        self::assertSame('version:2', DebtUpdateRunner::perform($c, $first));
        $process = proc_open([PHP_BINARY, '-r', 'require "tests/bootstrap.php"; \App\Tests\Fixture\Concurrency\DebtUpdateRunner::main();'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 12);
        try {
            $pid = (int) fgets($pipes[1]);
            self::assertGreaterThan(0, $pid);
            fwrite($pipes[0], json_encode($second, JSON_THROW_ON_ERROR)."\n");
            fflush($pipes[0]);
            $deadline = microtime(true) + 4;
            do {
                $blocked = (int) $this->db->fetchOne('SELECT cardinality(pg_blocking_pids(?))', [$pid]);
            } while (0 === $blocked && microtime(true) < $deadline);
            self::assertGreaterThan(0, $blocked, 'Contender must wait on a real PostgreSQL lock.');
            if ('payer deactivation' === $race || 'current deactivation' === $race) {
                $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$this->users['payer deactivation' === $race ? 1 : 2]]);
            }
            if ('rollback' === $race) {
                $this->db->rollBack();
            } else {
                $this->db->commit();
            }
            $result = trim((string) fgets($pipes[1]));
            self::assertSame(match ($race) {
                'stale' => 'VERSION_CONFLICT',
                'payer deactivation' => 'DEBT_FROZEN',
                'current deactivation' => 'version:3',
                default => 'version:2',
            }, $result);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
        }
        $amount = in_array($race, ['rollback', 'current deactivation'], true) ? 1401 : 1201;
        self::assertSame($amount, (int) $this->db->fetchOne('SELECT total_amount FROM debt WHERE id=?', [$this->debtId]));
        self::assertSame('current deactivation' === $race ? 3 : 2, (int) $this->db->fetchOne('SELECT version FROM debt WHERE id=?', [$this->debtId]));
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt_participant WHERE debt_id=? AND is_deleted=false', [$this->debtId]));
        $rows = $this->db->fetchAllAssociative('SELECT amount,version FROM transfer WHERE debt_id=?', [$this->debtId]);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame(intdiv($amount + 2, 3), $row['amount']);
            self::assertSame('current deactivation' === $race ? 3 : 2, $row['version']);
        }
    }

    /** @return iterable<array{string}> */
    public static function races(): iterable
    {
        foreach (['stale', 'rollback', 'payer deactivation', 'current deactivation'] as $race) {
            yield [$race];
        }
    }
}
