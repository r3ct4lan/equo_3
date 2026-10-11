<?php

declare(strict_types=1);

namespace App\Tests\Integration\Debts;

use App\Tests\Fixture\Concurrency\DebtCommandRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

final class FinancialConcurrencyTest extends KernelTestCase
{
    private Connection $db;
    /** @var list<string> */ private array $users = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        for ($n = 0; $n < 3; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->users[] = $id;
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Concurrent finance', 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => true, 'created_at' => '2026-10-11 12:00:00+00'], ['is_active' => 'boolean']);
        }
        foreach ([$this->users[1], $this->users[2]] as $other) {
            $pair = [$this->users[0], $other];
            sort($pair);
            $this->db->insert('connect', ['id' => Uuid::v4()->toRfc4122(), 'first_user_id' => $pair[0], 'second_user_id' => $pair[1], 'created_at' => '2026-10-11 12:00:00+00']);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        foreach (['transfer' => 'sender_id', 'debt_participant' => 'user_id', 'debt' => 'created_by_id', 'idempotency_record' => 'user_id', 'connect' => 'first_user_id'] as $table => $column) {
            $this->db->executeStatement('DELETE FROM '.$table.' WHERE '.$column.' IN (?,?,?)', $this->users);
            if ('connect' === $table) {
                $this->db->executeStatement('DELETE FROM connect WHERE second_user_id IN (?,?,?)', $this->users);
            }
        }
        foreach ($this->users as $id) {
            $this->db->delete('app_user', ['id' => $id]);
        }
        parent::tearDown();
    }

    #[DataProvider('races')]
    public function testActualDatabaseLockProtectsCreateAndConnectGetOrCreate(string $race): void
    {
        $c = self::getContainer();
        self::assertInstanceOf(TestContainer::class, $c);
        [$a,$payer,$other] = $this->users;
        $first = ['author' => $a, 'payer' => $payer, 'other' => $other, 'key' => Uuid::v4()->toRfc4122(), 'amount' => 1001];
        $second = $first;
        if ('different key' === $race || 'deactivation' === $race) {
            $second['key'] = Uuid::v4()->toRfc4122();
        }
        if ('changed body' === $race) {
            $second['amount'] = 2001;
        }
        $this->db->beginTransaction();
        $expected = DebtCommandRunner::perform($c, $first);
        $process = proc_open([PHP_BINARY, '-r', 'require "tests/bootstrap.php"; \App\Tests\Fixture\Concurrency\DebtCommandRunner::main();'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
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
            if ('deactivation' === $race) {
                $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$other]);
            }
            if ('rollback' === $race) {
                $this->db->rollBack();
            } else {
                $this->db->commit();
            }
            $actual = trim((string) fgets($pipes[1]));
            if ('same' === $race) {
                self::assertSame($expected, $actual);
            } elseif ('changed body' === $race) {
                self::assertSame('IDEMPOTENCY_KEY_REUSED', $actual);
            } elseif ('deactivation' === $race) {
                self::assertSame('INACTIVE_USER', $actual);
            } else {
                self::assertTrue(Uuid::isValid($actual));
                self::assertNotSame($expected, $actual);
            }
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            } proc_terminate($process);
            proc_close($process);
        }
        $debts = 'different key' === $race ? 2 : 1;
        self::assertSame($debts, (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt WHERE created_by_id=?', [$a]));
        self::assertSame($debts * 3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM debt_participant WHERE user_id IN (?,?,?)', $this->users));
        self::assertSame($debts * 2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM transfer WHERE sender_id=?', [$payer]));
        self::assertSame($debts, (int) $this->db->fetchOne('SELECT COUNT(*) FROM idempotency_record WHERE user_id=?', [$a]));
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect WHERE first_user_id IN (?,?,?) OR second_user_id IN (?,?,?)', [...$this->users, ...$this->users]));
    }

    /** @return iterable<string,array{string}> */
    public static function races(): iterable
    {
        foreach (['same', 'changed body', 'different key', 'rollback', 'deactivation'] as $race) {
            yield $race => [$race];
        }
    }
}
