<?php

declare(strict_types=1);

namespace App\Tests\Integration\Connects;

use App\Tests\Fixture\Concurrency\ConnectCommandRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

final class ConnectCreationConcurrencyTest extends KernelTestCase
{
    private Connection $db;
    /** @var list<string> */ private array $users = [];
    private string $debtId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        for ($index = 0; $index < 3; ++$index) {
            $id = Uuid::v4()->toRfc4122();
            $this->users[] = $id;
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Concurrent connect', 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => true, 'created_at' => '2026-10-25 12:00:00+00'], ['is_active' => 'boolean']);
        }
        $this->debtId = Uuid::v4()->toRfc4122();
        $this->db->insert('debt', ['id' => $this->debtId, 'title' => 'Concurrent shared debt', 'description' => null, 'total_amount' => 900, 'payer_id' => $this->users[0], 'created_by_id' => $this->users[0], 'created_at' => '2026-10-25 12:00:00+00', 'is_deleted' => false, 'version' => 1], ['is_deleted' => 'boolean']);
        foreach ($this->users as $userId) {
            $this->db->insert('debt_participant', ['id' => Uuid::v4()->toRfc4122(), 'debt_id' => $this->debtId, 'user_id' => $userId, 'created_at' => '2026-10-25 12:00:00+00', 'is_deleted' => false], ['is_deleted' => 'boolean']);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        $this->db->executeStatement('DELETE FROM idempotency_record WHERE user_id=?', [$this->users[0]]);
        $this->db->executeStatement('DELETE FROM connect WHERE first_user_id IN (?,?,?) OR second_user_id IN (?,?,?)', [...$this->users, ...$this->users]);
        $this->db->delete('debt_participant', ['debt_id' => $this->debtId]);
        $this->db->delete('debt', ['id' => $this->debtId]);
        foreach ($this->users as $userId) {
            $this->db->delete('app_user', ['id' => $userId]);
        }
        parent::tearDown();
    }

    #[DataProvider('races')]
    public function testDatabaseLocksProtectIdempotencyEligibilityAndCanonicalPair(string $race): void
    {
        $container = self::getContainer();
        self::assertInstanceOf(TestContainer::class, $container);
        [$author, $other, $alternate] = $this->users;
        $key = Uuid::v4()->toRfc4122();
        $first = ['author' => $author, 'other' => $other, 'debt' => $this->debtId, 'key' => $key];
        $second = $first;
        if (in_array($race, ['different key', 'deleted debt'], true)) {
            $second['key'] = Uuid::v4()->toRfc4122();
        }
        if ('changed body' === $race) {
            $second['other'] = $alternate;
        }

        $this->db->beginTransaction();
        $expected = ConnectCommandRunner::perform($container, $first);
        $process = proc_open([PHP_BINARY, '-r', 'require "tests/bootstrap.php"; \\App\\Tests\\Fixture\\Concurrency\\ConnectCommandRunner::main();'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
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
            if ('deleted debt' === $race) {
                $this->db->update('debt', ['is_deleted' => true], ['id' => $this->debtId], ['is_deleted' => 'boolean']);
            }
            $this->db->commit();
            $actual = trim((string) fgets($pipes[1]));
            if ('changed body' === $race) {
                self::assertSame('IDEMPOTENCY_KEY_REUSED', $actual);
            } elseif ('deleted debt' === $race) {
                self::assertSame('DEBT_NOT_AVAILABLE', $actual);
            } else {
                self::assertSame($expected, $actual);
            }
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
        }

        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect WHERE first_user_id IN (?,?) AND second_user_id IN (?,?)', [$author, $other, $author, $other]));
        self::assertSame('different key' === $race ? 2 : 1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM idempotency_record WHERE user_id=? AND operation=?', [$author, 'connect.create']));
    }

    /** @return iterable<string, array{string}> */
    public static function races(): iterable
    {
        foreach (['same key', 'changed body', 'different key', 'deleted debt'] as $race) {
            yield $race => [$race];
        }
    }
}
