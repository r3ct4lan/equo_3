<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\Tests\Fixture\Concurrency\SessionCommandRunner;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

final class LogoutConcurrencyTest extends KernelTestCase
{
    private Connection $connection;
    /** @var array{user: string, refresh: string, csrf: string} */
    private array $credentials;

    protected function setUp(): void
    {
        foreach ([
            'EQUO_CSRF_SIGNING_KEY_VERSION' => 'v1',
            'EQUO_CSRF_SIGNING_KEY_RING' => '{"v1":"Y3NyZi1jb25jdXJyZW5jeS10ZXN0LWtleS0zMi1ieXRlcyEhIQ=="}',
        ] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $user = '8a000000-0000-4000-8000-'.sprintf('%012d', random_int(1, 999999999999));
        $session = '8a000000-0000-4000-8001-'.sprintf('%012d', random_int(1, 999999999999));
        $issued = $container->get(RefreshTokenCodecPort::class)->issueForSession($session);
        $now = new DateTimeImmutable('now');
        $this->connection->insert('app_user', [
            'id' => $user, 'name' => 'Concurrent Logout', 'email' => $user.'@example.test',
            'password_hash' => $container->get(PasswordHashingPort::class)->hash('Correct password!'),
            'is_active' => 1, 'created_at' => $now->modify('-1 day')->format('Y-m-d H:i:sP'),
        ]);
        $this->connection->insert('user_session', [
            'id' => $session, 'user_id' => $user, 'refresh_token_hash' => $issued->tokenHash,
            'created_at' => $now->modify('-1 day')->format('Y-m-d H:i:sP'),
            'expires_at' => $now->modify('+29 days')->format('Y-m-d H:i:sP'), 'revoked_at' => null,
        ]);
        $this->credentials = ['user' => $user, 'refresh' => $issued->publicToken, 'csrf' => $container->get(CsrfTokenCodecPort::class)->issue($session)];
        $container->get('test.rate_limiter_cache')->clear();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection->executeStatement('DELETE FROM user_session WHERE user_id = ?', [$this->credentials['user']]);
        $this->connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$this->credentials['user']]);
        parent::tearDown();
    }

    #[DataProvider('orders')]
    public function testRealTransactionsRespectBothSerialOrders(string $first, string $second, string $outcome, int $activeSessions): void
    {
        $this->connection->beginTransaction();
        self::assertSame('ok', SessionCommandRunner::perform($this->testContainer(), ['operation' => $first] + $this->credentials));
        $firstRevokedAt = $this->connection->fetchOne('SELECT revoked_at FROM user_session WHERE user_id = ? ORDER BY created_at LIMIT 1', [$this->credentials['user']]);
        $process = proc_open(
            [PHP_BINARY, '-r', 'require "tests/bootstrap.php"; \\App\\Tests\\Fixture\\Concurrency\\SessionCommandRunner::main();'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 12);
        try {
            $backendPid = (int) fgets($pipes[1]);
            self::assertGreaterThan(0, $backendPid);
            fwrite($pipes[0], json_encode(['operation' => $second] + $this->credentials, JSON_THROW_ON_ERROR)."\n");
            fflush($pipes[0]);
            $deadline = microtime(true) + 4;
            do {
                $blocked = (int) $this->connection->fetchOne('SELECT cardinality(pg_blocking_pids(?))', [$backendPid]);
            } while (0 === $blocked && microtime(true) < $deadline);
            self::assertGreaterThan(0, $blocked, 'Contender must wait on the first transaction; no timing sleeps are used.');
            $this->connection->commit();
            self::assertSame($outcome, trim((string) fgets($pipes[1])));
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
        }
        self::assertSame($activeSessions, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_session WHERE user_id = ? AND revoked_at IS NULL AND expires_at > now()', [$this->credentials['user']],
        ));
        if (null !== $firstRevokedAt && 'login' !== $first) {
            self::assertSame($firstRevokedAt, $this->connection->fetchOne(
                'SELECT revoked_at FROM user_session WHERE user_id = ? ORDER BY created_at LIMIT 1', [$this->credentials['user']],
            ));
        }
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function orders(): iterable
    {
        yield 'refresh then current' => ['refresh', 'current', 'ok', 0];
        yield 'current then refresh' => ['current', 'refresh', 'INVALID_REFRESH_TOKEN', 0];
        yield 'two current' => ['current', 'current', 'ok', 0];
        yield 'refresh then all' => ['refresh', 'all', 'ok', 0];
        yield 'all then refresh' => ['all', 'refresh', 'INVALID_REFRESH_TOKEN', 0];
        yield 'two all' => ['all', 'all', 'ok', 0];
        yield 'login then all' => ['login', 'all', 'ok', 0];
        yield 'all then login' => ['all', 'login', 'ok', 1];
    }

    public function testSingleSessionLockDoesNotAlsoLockUser(): void
    {
        $this->connection->beginTransaction();
        self::assertSame('ok', SessionCommandRunner::perform($this->testContainer(), ['operation' => 'refresh'] + $this->credentials));
        $other = DriverManager::getConnection($this->connection->getParams());
        $other->beginTransaction();
        try {
            $other->executeStatement("SET LOCAL lock_timeout = '200ms'");
            self::assertSame($this->credentials['user'], $other->fetchOne('SELECT id FROM app_user WHERE id = ? FOR UPDATE', [$this->credentials['user']]));
        } finally {
            $other->rollBack();
            $other->close();
        }
    }

    private function testContainer(): TestContainer
    {
        $container = self::getContainer();
        self::assertInstanceOf(TestContainer::class, $container);

        return $container;
    }
}
