<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Application\PasswordReset\PasswordResetRequestCommand;
use App\IdentityAccess\Application\PasswordReset\RequestPasswordReset;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use App\Tests\Fixture\Concurrency\SessionCommandRunner;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

final class PasswordResetConcurrencyTest extends KernelTestCase
{
    private Connection $db;
    /** @var array{user: string, refresh: string, csrf: string, token: string} */
    private array $credentials;

    protected function setUp(): void
    {
        foreach (['EQUO_CSRF_SIGNING_KEY_VERSION' => 'v1', 'EQUO_CSRF_SIGNING_KEY_RING' => '{"v1":"Y3NyZi1jb25jdXJyZW5jeS10ZXN0LWtleS0zMi1ieXRlcyEhIQ=="}'] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        self::bootKernel();
        $container = self::getContainer();
        $this->db = $container->get(Connection::class);
        $user = '8b000000-0000-4000-8000-'.sprintf('%012d', random_int(1, 999999999999));
        $session = '8b000000-0000-4000-8001-'.sprintf('%012d', random_int(1, 999999999999));
        $issued = $container->get(RefreshTokenCodecPort::class)->issueForSession($session);
        $now = new DateTimeImmutable();
        $this->db->insert('app_user', ['id' => $user, 'name' => 'Concurrent Recovery', 'email' => $user.'@example.test', 'password_hash' => $container->get(PasswordHashingPort::class)->hash('Correct password!'), 'is_active' => 1, 'created_at' => $now->modify('-1 day')->format('Y-m-d H:i:sP')]);
        $this->db->insert('user_session', ['id' => $session, 'user_id' => $user, 'refresh_token_hash' => $issued->tokenHash, 'created_at' => $now->modify('-1 day')->format('Y-m-d H:i:sP'), 'expires_at' => $now->modify('+29 days')->format('Y-m-d H:i:sP')]);
        $container->get('test.rate_limiter_cache')->clear();
        $container->get(RequestPasswordReset::class)->handle(new PasswordResetRequestCommand($user.'@example.test', '192.0.2.82'));
        $ciphertext = $this->db->fetchOne('SELECT encrypted_payload FROM email_delivery_outbox WHERE recipient_email = ?', [$user.'@example.test']);
        $ciphertext = is_resource($ciphertext) ? stream_get_contents($ciphertext) : $ciphertext;
        self::assertIsString($ciphertext);
        $token = $container->get(PayloadCipher::class)->decrypt($ciphertext)['token'];
        $this->credentials = ['user' => $user, 'refresh' => $issued->publicToken, 'csrf' => $container->get(CsrfTokenCodecPort::class)->issue($session), 'token' => $token];
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        $owner = $this->credentials['user'];
        $this->db->executeStatement('DELETE FROM email_delivery_outbox WHERE user_action_token_id IN (SELECT id FROM user_action_token WHERE user_id = ?)', [$owner]);
        $this->db->executeStatement('DELETE FROM user_action_token WHERE user_id = ?', [$owner]);
        $this->db->executeStatement('DELETE FROM user_session WHERE user_id = ?', [$owner]);
        $this->db->executeStatement('DELETE FROM app_user WHERE id = ?', [$owner]);
        parent::tearDown();
    }

    #[DataProvider('orders')]
    public function testRealProcessesRespectResetSerialOrderAndFreshCredentialChecks(string $first, string $second, string $outcome, int $used, int $unfinished, int $sessions): void
    {
        $this->db->beginTransaction();
        self::assertSame('ok', SessionCommandRunner::perform($this->container(), ['operation' => $first] + $this->credentials));
        $process = proc_open([PHP_BINARY, '-r', 'require "tests/bootstrap.php"; \\App\\Tests\\Fixture\\Concurrency\\SessionCommandRunner::main();'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 12);
        try {
            $pid = (int) fgets($pipes[1]);
            self::assertGreaterThan(0, $pid);
            fwrite($pipes[0], json_encode(['operation' => $second] + $this->credentials, JSON_THROW_ON_ERROR)."\n");
            fflush($pipes[0]);
            $deadline = microtime(true) + 4;
            do {
                $blocked = (int) $this->db->fetchOne('SELECT cardinality(pg_blocking_pids(?))', [$pid]);
            } while (0 === $blocked && microtime(true) < $deadline);
            self::assertGreaterThan(0, $blocked, 'The contender must demonstrably wait on a real database lock.');
            $this->db->commit();
            self::assertSame($outcome, trim((string) fgets($pipes[1])));
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
        }
        $owner = $this->credentials['user'];
        self::assertSame($used, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE user_id = ? AND used_at IS NOT NULL', [$owner]));
        self::assertSame($unfinished, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE user_id = ? AND used_at IS NULL AND invalidated_at IS NULL', [$owner]));
        self::assertSame($sessions, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id = ? AND revoked_at IS NULL AND expires_at > now()', [$owner]));
        $hash = $this->db->fetchOne('SELECT password_hash FROM app_user WHERE id = ?', [$owner]);
        self::assertIsString($hash);
        $passwordChanged = 1 === $used;
        self::assertSame($passwordChanged, $this->container()->get(PasswordHashingPort::class)->verify('New password1!', $hash));
        self::assertSame(!$passwordChanged, $this->container()->get(PasswordHashingPort::class)->verify('Correct password!', $hash));
    }

    /** @return iterable<string, array{string, string, string, int, int, int}> */
    public static function orders(): iterable
    {
        yield 'two reset applies' => ['reset', 'reset', 'TOKEN_USED', 1, 0, 0];
        yield 'reissue wins' => ['request-reset', 'reset', 'TOKEN_INVALIDATED', 0, 1, 1];
        yield 'apply before reissue' => ['reset', 'request-reset', 'ok', 1, 1, 0];
        yield 'two reissues' => ['request-reset', 'request-reset', 'ok', 0, 1, 1];
        yield 'login first is revoked' => ['login', 'reset', 'ok', 1, 0, 0];
        yield 'waiting old password login is rejected' => ['reset', 'login', 'INVALID_CREDENTIALS', 1, 0, 0];
        yield 'refresh first is revoked' => ['refresh', 'reset', 'ok', 1, 0, 0];
        yield 'waiting refresh sees revoked session' => ['reset', 'refresh', 'INVALID_REFRESH_TOKEN', 1, 0, 0];
        yield 'wrong-purpose activation waits for reset without deadlock' => ['reset', 'activate', 'INVALID_TOKEN', 1, 0, 0];
    }

    public function testActivationCannotLockResetTokenBeforeItsOwner(): void
    {
        $this->db->beginTransaction();
        self::assertNotNull($this->container()->get(IdentityRepositoryPort::class)->currentUserForUpdate($this->credentials['user']));
        $process = proc_open([PHP_BINARY, '-r', 'require "tests/bootstrap.php"; \\App\\Tests\\Fixture\\Concurrency\\SessionCommandRunner::main();'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 12);
        try {
            $pid = (int) fgets($pipes[1]);
            fwrite($pipes[0], json_encode(['operation' => 'activate'] + $this->credentials, JSON_THROW_ON_ERROR)."\n");
            fflush($pipes[0]);
            $deadline = microtime(true) + 4;
            do {
                $blocked = (int) $this->db->fetchOne('SELECT cardinality(pg_blocking_pids(?))', [$pid]);
            } while (0 === $blocked && microtime(true) < $deadline);
            self::assertGreaterThan(0, $blocked);
            $probe = DriverManager::getConnection($this->db->getParams());
            $probe->beginTransaction();
            $acquired = false;
            try {
                $digest = $this->container()->get(ActionTokenCodecPort::class)->digest($this->credentials['token']);
                $probe->fetchOne('SELECT id FROM user_action_token WHERE token_hash = ? FOR UPDATE NOWAIT', [$digest]);
                $acquired = true;
            } catch (DriverException) {
                // Keep SQL parameters and capability fingerprints out of test diagnostics.
            } finally {
                $probe->rollBack();
                $probe->close();
            }
            $this->db->commit();
            self::assertSame('INVALID_TOKEN', trim((string) fgets($pipes[1])));
            self::assertTrue($acquired, 'An activation waiting for User must not already hold a reset token lock.');
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
        }
    }

    private function container(): TestContainer
    {
        $container = self::getContainer();
        self::assertInstanceOf(TestContainer::class, $container);

        return $container;
    }
}
