<?php

declare(strict_types=1);

namespace App\Tests\Integration\Invitations;

use App\Tests\Fixture\Concurrency\InvitationCommandRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

final class InvitationConcurrencyTest extends KernelTestCase
{
    private Connection $db;
    /** @var list<string> */ private array $users = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        self::getContainer()->get('test.rate_limiter_cache')->clear();
        for ($n = 0; $n < 3; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->users[] = $id;
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Concurrent invitation', 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00']);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        foreach ($this->users as $id) {
            $this->db->executeStatement('DELETE FROM connect_invitation WHERE created_by_id = ? OR accepted_by_id = ?', [$id, $id]);
            $this->db->executeStatement('DELETE FROM connect WHERE first_user_id = ? OR second_user_id = ?', [$id, $id]);
        }
        foreach ($this->users as $id) {
            $this->db->executeStatement('DELETE FROM app_user WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    #[DataProvider('races')]
    public function testRealDatabaseLockSerializesConcurrentCommands(string $race): void
    {
        $c = self::getContainer();
        self::assertInstanceOf(TestContainer::class, $c);
        [$a,$b,$third] = $this->users;
        $token = $c->get('test.invitation_service')->create($a)['token'];
        $reverse = 'reverse' === $race ? $c->get('test.invitation_service')->create($b)['token'] : $token;
        $first = ['operation' => 'create', 'user' => $a, 'token' => ''];
        $second = $first;
        if ('create' !== $race) {
            $first = ['operation' => 'accept', 'user' => $b, 'token' => $token];
            $second = ['operation' => 'accept', 'user' => 'other' === $race ? $third : ('reverse' === $race ? $a : $b), 'token' => $reverse];
        }
        $this->db->beginTransaction();
        $expected = InvitationCommandRunner::perform($c, $first);
        $process = proc_open([PHP_BINARY, '-r', 'require "tests/bootstrap.php"; \\App\\Tests\\Fixture\\Concurrency\\InvitationCommandRunner::main();'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
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
            self::assertGreaterThan(0, $blocked, 'Contender must demonstrably wait on a real PostgreSQL lock.');
            $this->db->commit();
            self::assertSame('other' === $race ? 'TOKEN_USED' : $expected, trim((string) fgets($pipes[1])));
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }proc_terminate($process);
            proc_close($process);
        }
        self::assertSame('create' === $race ? 0 : 1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect WHERE first_user_id IN (?,?) AND second_user_id IN (?,?)', [$a, $b, $a, $b]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect_invitation WHERE created_by_id = ?', [$a]));
    }

    /** @return iterable<string, array{string}> */
    public static function races(): iterable
    {
        yield 'two get-or-create' => ['create'];
        yield 'same acceptor' => ['same'];
        yield 'different acceptors' => ['other'];
        yield 'reciprocal invitations' => ['reverse'];
    }
}
