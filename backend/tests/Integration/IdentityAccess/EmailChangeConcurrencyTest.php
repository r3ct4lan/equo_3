<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Application\EmailChange\EmailChangeRequestCommand;
use App\IdentityAccess\Application\EmailChange\RequestEmailChange;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\PayloadCipherPort;
use App\Tests\Fixture\Concurrency\SessionCommandRunner;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

final class EmailChangeConcurrencyTest extends KernelTestCase
{
    private Connection $db;
    /** @var array{operation: string, user: string, refresh: string, csrf: string, token: string} */
    private array $input;

    protected function setUp(): void
    {
        foreach (['EQUO_CSRF_SIGNING_KEY_VERSION' => 'v1', 'EQUO_CSRF_SIGNING_KEY_RING' => '{"v1":"Y3NyZi1jb25jdXJyZW5jeS10ZXN0LWtleS0zMi1ieXRlcyEhIQ=="}'] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        self::bootKernel();
        $container = $this->container();
        $this->db = $container->get(Connection::class);
        $container->get('test.rate_limiter_cache')->clear();
        $user = '8f000000-0000-4000-8000-'.sprintf('%012d', random_int(1, 999999999999));
        $now = new DateTimeImmutable();
        $this->db->insert('app_user', [
            'id' => $user, 'name' => 'Concurrent Email', 'email' => $user.'@example.test',
            'password_hash' => $container->get(PasswordHashingPort::class)->hash('Correct password!'),
            'is_active' => 1, 'created_at' => $now->modify('-1 day')->format('Y-m-d H:i:sP'),
        ]);
        $this->db->insert('user_session', [
            'id' => '8f000000-0000-4000-8001-'.sprintf('%012d', random_int(1, 999999999999)),
            'user_id' => $user, 'refresh_token_hash' => 'sha256:'.hash('sha256', $user),
            'created_at' => $now->modify('-1 day')->format('Y-m-d H:i:sP'),
            'expires_at' => $now->modify('+29 days')->format('Y-m-d H:i:sP'),
        ]);
        $container->get(RequestEmailChange::class)->handle(new EmailChangeRequestCommand($user, 'Correct password!', 'new-'.$user.'@example.test'));
        $ciphertext = $this->db->fetchOne('SELECT encrypted_payload FROM email_delivery_outbox WHERE recipient_email = ?', ['new-'.$user.'@example.test']);
        $ciphertext = is_resource($ciphertext) ? stream_get_contents($ciphertext) : $ciphertext;
        self::assertIsString($ciphertext);
        $token = $container->get(PayloadCipherPort::class)->decrypt($ciphertext)['token'];
        $this->input = ['operation' => 'confirm-email-change', 'user' => $user, 'refresh' => '', 'csrf' => '', 'token' => $token];
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        if (isset($this->input)) {
            $owner = $this->input['user'];
            $this->db->executeStatement('DELETE FROM email_delivery_outbox WHERE user_action_token_id IN (SELECT id FROM user_action_token WHERE user_id = ?)', [$owner]);
            $this->db->executeStatement('DELETE FROM user_action_token WHERE user_id = ?', [$owner]);
            $this->db->executeStatement('DELETE FROM user_session WHERE user_id = ?', [$owner]);
            $this->db->executeStatement('DELETE FROM app_user WHERE id = ?', [$owner]);
        }
        parent::tearDown();
    }

    public function testTwoConfirmationsSerializeAndOnlyOneConsumesToken(): void
    {
        $this->db->beginTransaction();
        self::assertSame('ok', SessionCommandRunner::perform($this->container(), $this->input));
        $process = proc_open([PHP_BINARY, '-r', 'require "tests/bootstrap.php"; \\App\\Tests\\Fixture\\Concurrency\\SessionCommandRunner::main();'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 12);
        try {
            $pid = (int) fgets($pipes[1]);
            self::assertGreaterThan(0, $pid);
            fwrite($pipes[0], json_encode($this->input, JSON_THROW_ON_ERROR)."\n");
            fflush($pipes[0]);
            $deadline = microtime(true) + 4;
            do {
                $blocked = (int) $this->db->fetchOne('SELECT cardinality(pg_blocking_pids(?))', [$pid]);
            } while (0 === $blocked && microtime(true) < $deadline);
            self::assertGreaterThan(0, $blocked, 'The second confirmation must wait for the account lock.');
            $this->db->commit();
            self::assertSame('TOKEN_USED', trim((string) fgets($pipes[1])));
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
        }

        $owner = $this->input['user'];
        self::assertSame('new-'.$owner.'@example.test', $this->db->fetchOne('SELECT email FROM app_user WHERE id = ?', [$owner]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE user_id = ? AND used_at IS NOT NULL', [$owner]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id = ? AND revoked_at IS NULL', [$owner]));
    }

    private function container(): TestContainer
    {
        $container = self::getContainer();
        self::assertInstanceOf(TestContainer::class, $container);

        return $container;
    }
}
