<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Application\Port\TelegramIdentityRepositoryPort;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class TelegramSignInConcurrencyTest extends KernelTestCase
{
    public function testConcurrentFirstLaunchCreatesOneAccountWithTwoSessions(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $telegramId = random_int(4294967297, 9000000000);
        $first = $this->launch($telegramId);
        $second = $this->launch($telegramId);
        $db->beginTransaction();

        try {
            self::getContainer()->get(TelegramIdentityRepositoryPort::class)->lockIdentity($telegramId);
            $first->start();
            $second->start();
            $deadline = microtime(true) + 8;
            do {
                $waiting = (int) $db->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted");
                if ($waiting >= 2 || !$first->isRunning() || !$second->isRunning()) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);

            self::assertGreaterThanOrEqual(2, $waiting, 'Both contenders should wait for the first-login lock.');
            $db->commit();
            self::assertSame(0, $first->wait());
            self::assertSame(0, $second->wait());
            $account = trim($first->getOutput());
            self::assertNotSame('', $account);
            self::assertSame($account, trim($second->getOutput()));
            self::assertSame($account, $db->fetchOne('SELECT id FROM app_user WHERE telegram_id = ?', [$telegramId]));
            self::assertSame(2, (int) $db->fetchOne('SELECT count(*) FROM user_session WHERE user_id = ?', [$account]));
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $first->stop();
            $second->stop();
            $db->executeStatement('DELETE FROM user_session WHERE user_id IN (SELECT id FROM app_user WHERE telegram_id = ?)', [$telegramId]);
            $db->executeStatement('DELETE FROM app_user WHERE telegram_id = ?', [$telegramId]);
        }
    }

    private function launch(int $telegramId): Process
    {
        $worker = <<<'PHP'
            require 'tests/bootstrap.php';
            $kernel = new App\Kernel('test', true);
            $kernel->boot();
            $signIn = $kernel->getContainer()->get('test.service_container')->get(App\IdentityAccess\Application\Login\SignInWithTelegram::class);
            $identity = new App\IdentityAccess\Domain\User\VerifiedTelegramIdentity((int) $argv[1], 'Concurrent Telegram user');
            fwrite(STDOUT, $signIn->handle($identity)->user->id);
            PHP;

        return new Process([PHP_BINARY, '-r', $worker, (string) $telegramId], dirname(__DIR__, 3), timeout: 15);
    }
}
