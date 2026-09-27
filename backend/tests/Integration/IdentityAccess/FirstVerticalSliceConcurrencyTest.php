<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Application\Activate\ActivateAccount;
use App\IdentityAccess\Application\Activate\ActivateCommand;
use App\IdentityAccess\Application\ActivationRequest\ActivationRequestCommand;
use App\IdentityAccess\Application\ActivationRequest\RequestActivation;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Register\RegisterCommand;
use App\IdentityAccess\Application\Register\RegisterUser;
use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

final class FirstVerticalSliceConcurrencyTest extends KernelTestCase
{
    /** @var list<string> */
    private array $emails = [];

    /** @var list<string> */
    private array $idempotencyKeys = [];

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        foreach ($this->emails as $email) {
            $userId = $connection->fetchOne('SELECT id FROM app_user WHERE email = ?', [$email]);

            if (false !== $userId) {
                $connection->executeStatement(
                    'DELETE FROM email_delivery_outbox WHERE user_action_token_id IN (SELECT id FROM user_action_token WHERE user_id = ?)',
                    [$userId],
                );
                $connection->executeStatement('DELETE FROM user_action_token WHERE user_id = ?', [$userId]);
                $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$userId]);
            }
        }

        foreach ($this->idempotencyKeys as $key) {
            $connection->executeStatement('DELETE FROM idempotency_record WHERE idempotency_key = ?', [$key]);
        }

        parent::tearDown();
    }

    public function testConcurrentReplayReturnsOneResultAndCreatesOneBusinessSet(): void
    {
        $email = $this->email('same-key');
        $key = $this->key('10000000');
        $command = new RegisterCommand('Concurrent User', $email, 'A2345678901!', $key, '198.51.100.10');

        $results = $this->race([
            fn (): array => $this->registrationOutcome($command),
            fn (): array => $this->registrationOutcome($command),
        ]);

        self::assertSame(['created', 'created'], array_column($results, 'outcome'));
        self::assertSame($results[0]['body'], $results[1]['body']);
        $this->assertRegistrationSet($email, $key, 1);
    }

    public function testConcurrentRegistrationOfOneEmailWithDifferentKeysHasOneWinner(): void
    {
        $email = $this->email('same-email');
        $firstKey = $this->key('20000000');
        $secondKey = $this->key('30000000');

        $results = $this->race([
            fn (): array => $this->registrationOutcome(new RegisterCommand(
                'First User',
                $email,
                'A2345678901!',
                $firstKey,
                '198.51.100.20',
            )),
            fn (): array => $this->registrationOutcome(new RegisterCommand(
                'Second User',
                $email,
                'A2345678901!',
                $secondKey,
                '198.51.100.21',
            )),
        ]);

        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        self::assertSame(['EMAIL_ALREADY_EXISTS', 'created'], $outcomes);
        $this->assertRegistrationSet($email, null, 1);
    }

    public function testConcurrentActivationUsesTokenExactlyOnce(): void
    {
        $email = $this->email('activate');
        $key = $this->key('40000000');

        self::bootKernel();
        $container = self::getContainer();
        $result = $container->get('test.register_user')->handle(new RegisterCommand(
            'Activation User',
            $email,
            'A2345678901!',
            $key,
            '198.51.100.30',
        ));
        $connection = $container->get(Connection::class);
        $encryptedPayload = $connection->fetchOne(
            'SELECT encrypted_payload FROM email_delivery_outbox WHERE user_action_token_id = (SELECT id FROM user_action_token WHERE user_id = ?)',
            [$result->user->id],
        );
        if (is_resource($encryptedPayload)) {
            $encryptedPayload = stream_get_contents($encryptedPayload);
        }
        self::assertIsString($encryptedPayload);
        $token = $container->get(PayloadCipher::class)->decrypt($encryptedPayload)['token'];
        self::ensureKernelShutdown();

        $results = $this->race([
            fn (): array => $this->activationOutcome($token, '198.51.100.31'),
            fn (): array => $this->activationOutcome($token, '198.51.100.32'),
        ]);

        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        self::assertSame(['TOKEN_USED', 'activated'], $outcomes);

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(true, $connection->fetchOne('SELECT is_active FROM app_user WHERE id = ?', [$result->user->id]));
        self::assertNotFalse($connection->fetchOne('SELECT used_at FROM user_action_token WHERE user_id = ?', [$result->user->id]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE user_id = ?', [$result->user->id]));
    }

    public function testConcurrentActivationRequestsLeaveOneCurrentTokenAndConsistentOutbox(): void
    {
        $email = $this->email('activation-request');
        $key = $this->key('41000000');

        self::bootKernel();
        $container = self::getContainer();
        $container->get('test.rate_limiter_cache')->clear();
        $result = $container->get('test.register_user')->handle(new RegisterCommand(
            'Concurrent Activation Request',
            $email,
            'A2345678901!',
            $key,
            '198.51.100.40',
        ));
        $userId = $result->user->id;
        self::ensureKernelShutdown();

        $results = $this->race([
            fn (): array => $this->activationRequestOutcome(new ActivationRequestCommand(
                $email,
                'A2345678901!',
                '198.51.100.41',
            )),
            fn (): array => $this->activationRequestOutcome(new ActivationRequestCommand(
                '  '.mb_strtoupper($email).'  ',
                'A2345678901!',
                '198.51.100.42',
            )),
        ]);

        self::assertSame(
            ['activation_email_scheduled', 'activation_email_scheduled'],
            array_column($results, 'outcome'),
        );

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(3, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM user_action_token WHERE user_id = ?',
            [$userId],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM user_action_token WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND invalidated_at IS NULL',
            [$userId, 'ACTIVATE_ACCOUNT'],
        ));
        self::assertSame(2, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM user_action_token WHERE user_id = ? AND purpose = ? AND invalidated_at IS NOT NULL',
            [$userId, 'ACTIVATE_ACCOUNT'],
        ));
        self::assertSame(3, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM email_delivery_outbox WHERE user_action_token_id IN (SELECT id FROM user_action_token WHERE user_id = ?)',
            [$userId],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM email_delivery_outbox outbox LEFT JOIN user_action_token token ON token.id = outbox.user_action_token_id WHERE token.id IS NULL',
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM email_delivery_outbox outbox INNER JOIN user_action_token token ON token.id = outbox.user_action_token_id WHERE token.user_id = ? AND token.used_at IS NULL AND token.invalidated_at IS NULL',
            [$userId],
        ));
    }

    /** @return array{outcome: string, body?: array<string, mixed>} */
    private function registrationOutcome(RegisterCommand $command): array
    {
        try {
            /** @var RegisterUser $handler */
            $handler = self::getContainer()->get('test.register_user');
            $result = $handler->handle($command);

            return ['outcome' => 'created', 'body' => $result->toArray()];
        } catch (ApplicationFailure $failure) {
            return ['outcome' => $failure->failureCode->value];
        }
    }

    /** @return array{outcome: string} */
    private function activationOutcome(string $token, string $ipAddress): array
    {
        try {
            /** @var ActivateAccount $handler */
            $handler = self::getContainer()->get('test.activate_account');
            $handler->handle(new ActivateCommand($token, $ipAddress));

            return ['outcome' => 'activated'];
        } catch (ApplicationFailure $failure) {
            return ['outcome' => $failure->failureCode->value];
        }
    }

    /** @return array{outcome: string} */
    private function activationRequestOutcome(ActivationRequestCommand $command): array
    {
        try {
            /** @var RequestActivation $handler */
            $handler = self::getContainer()->get('test.request_activation');
            $result = $handler->handle($command);

            return ['outcome' => $result->status];
        } catch (ApplicationFailure $failure) {
            return ['outcome' => $failure->failureCode->value];
        }
    }

    /**
     * @param list<callable(): array<string, mixed>> $operations
     *
     * @return list<array<string, mixed>>
     */
    private function race(array $operations): array
    {
        self::ensureKernelShutdown();
        $directory = sys_get_temp_dir().'/equo-race-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $releaseFile = $directory.'/release';
        $processes = [];

        foreach ($operations as $index => $operation) {
            $pid = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $pid);

            if (0 === $pid) {
                while (!is_file($releaseFile)) {
                    usleep(1_000);
                }

                try {
                    self::bootKernel();
                    $payload = ['result' => $operation()];
                } catch (Throwable $exception) {
                    $payload = ['exception' => $exception::class, 'message' => $exception->getMessage()];
                } finally {
                    self::ensureKernelShutdown();
                }

                file_put_contents($directory.'/result-'.$index.'.json', json_encode($payload, JSON_THROW_ON_ERROR));
                exit(0);
            }

            $processes[] = $pid;
        }

        touch($releaseFile);

        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $results = [];

        foreach (array_keys($operations) as $index) {
            $payload = json_decode((string) file_get_contents($directory.'/result-'.$index.'.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('exception', $payload, $payload['message'] ?? 'Concurrent operation failed.');
            $results[] = $payload['result'];
            unlink($directory.'/result-'.$index.'.json');
        }

        unlink($releaseFile);
        rmdir($directory);

        return $results;
    }

    private function assertRegistrationSet(string $email, ?string $key, int $expected): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $userId = $connection->fetchOne('SELECT id FROM app_user WHERE email = ?', [$email]);
        self::assertIsString($userId);
        self::assertSame($expected, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', [$email]));
        self::assertSame($expected, (int) $connection->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE user_id = ?', [$userId]));
        self::assertSame($expected, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM email_delivery_outbox WHERE user_action_token_id IN (SELECT id FROM user_action_token WHERE user_id = ?)',
            [$userId],
        ));

        if (null !== $key) {
            self::assertSame($expected, (int) $connection->fetchOne('SELECT COUNT(*) FROM idempotency_record WHERE idempotency_key = ?', [$key]));
        }
    }

    private function email(string $prefix): string
    {
        $email = sprintf('%s-%s@example.test', $prefix, bin2hex(random_bytes(6)));
        $this->emails[] = $email;

        return $email;
    }

    private function key(string $prefix): string
    {
        $key = sprintf('%s-0000-4000-8000-%012d', $prefix, random_int(1, 999999999999));
        $this->idempotencyKeys[] = $key;

        return $key;
    }
}
