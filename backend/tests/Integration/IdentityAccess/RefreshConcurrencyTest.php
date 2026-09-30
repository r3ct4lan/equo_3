<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RefreshConcurrencyTest extends WebTestCase
{
    private const string ORIGIN = 'https://equo.test';
    private const string CSRF_KEY_RING = '{"v1":"Y3NyZi1jb25jdXJyZW5jeS10ZXN0LWtleS0zMi1ieXRlcyEhIQ=="}';

    /** @var list<string> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->configureEnvironment();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        foreach ($this->userIds as $userId) {
            $connection->executeStatement('DELETE FROM user_session WHERE user_id = ?', [$userId]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$userId]);
        }

        parent::tearDown();
    }

    public function testTwoConcurrentRefreshRequestsRotateExactlyOnce(): void
    {
        $this->configureEnvironment();
        self::bootKernel();
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        $userId = '86000000-0000-4000-8000-'.sprintf('%012d', random_int(1, 999999999999));
        $sessionId = '86000000-0000-4000-8001-'.sprintf('%012d', random_int(1, 999999999999));
        $this->userIds[] = $userId;
        $issuedRefreshToken = $container->get(RefreshTokenCodecPort::class)->issue();
        $csrfToken = $container->get(CsrfTokenCodecPort::class)->issue($sessionId);
        $oldHash = $issuedRefreshToken->tokenHash;
        $createdAt = new DateTimeImmutable('-1 hour', new DateTimeZone('UTC'));
        $expiresAt = $createdAt->modify('+30 days');
        $connection->insert('app_user', [
            'id' => $userId,
            'name' => 'Concurrent Refresh',
            'email' => $userId.'@example.test',
            'password_hash' => $container->get(PasswordHashingPort::class)->hash('Correct password!'),
            'is_active' => 1,
            'created_at' => $createdAt->format('Y-m-d H:i:sP'),
        ]);
        $connection->insert('user_session', [
            'id' => $sessionId,
            'user_id' => $userId,
            'refresh_token_hash' => $oldHash,
            'created_at' => $createdAt->format('Y-m-d H:i:sP'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:sP'),
            'revoked_at' => null,
        ]);
        self::ensureKernelShutdown();

        $results = $this->race($issuedRefreshToken->publicToken, $csrfToken);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame([Response::HTTP_OK, Response::HTTP_UNAUTHORIZED], $statuses);

        $codes = array_column($results, 'code');
        sort($codes);
        self::assertSame(['INVALID_REFRESH_TOKEN', 'ok'], $codes);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ?', [$oldHash]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id = ?', [$userId]));
        $newHash = $connection->fetchOne('SELECT refresh_token_hash FROM user_session WHERE user_id = ?', [$userId]);
        self::assertIsString($newHash);
        self::assertNotSame($oldHash, $newHash);

        $winner = Response::HTTP_OK === $results[0]['status'] ? $results[0] : $results[1];
        self::assertIsString($winner['refresh'] ?? null);
        self::assertIsString($winner['csrf'] ?? null);

        $next = $this->refreshOutcome($winner['refresh'], $winner['csrf']);
        self::assertSame(Response::HTTP_OK, $next['status']);
        self::assertSame('ok', $next['code']);

        $replay = $this->refreshOutcome($winner['refresh'], $winner['csrf']);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $replay['status']);
        self::assertSame('INVALID_REFRESH_TOKEN', $replay['code']);
    }

    /** @return list<array{status: int, code: string, refresh?: string, csrf?: string}> */
    private function race(string $refreshToken, string $csrfToken): array
    {
        $directory = sys_get_temp_dir().'/equo-refresh-race-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $releaseFile = $directory.'/release';
        $processes = [];

        for ($index = 0; $index < 2; ++$index) {
            $pid = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $pid);

            if (0 === $pid) {
                while (!is_file($releaseFile)) {
                    usleep(1_000);
                }

                try {
                    $payload = ['result' => $this->refreshOutcome($refreshToken, $csrfToken)];
                } catch (Throwable $exception) {
                    $payload = ['exception' => $exception::class, 'message' => $exception->getMessage()];
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
        for ($index = 0; $index < 2; ++$index) {
            $payload = json_decode((string) file_get_contents($directory.'/result-'.$index.'.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('exception', $payload, $payload['message'] ?? 'Concurrent refresh failed.');
            $results[] = $payload['result'];
            unlink($directory.'/result-'.$index.'.json');
        }

        unlink($releaseFile);
        rmdir($directory);

        return $results;
    }

    /** @return array{status: int, code: string, refresh?: string, csrf?: string} */
    private function refreshOutcome(string $refreshToken, string $csrfToken): array
    {
        $this->configureEnvironment();
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->getCookieJar()->set(new Cookie('equo_refresh', $refreshToken, null, '/api/v1/auth', 'localhost', false));
        $client->getCookieJar()->set(new Cookie('__Host-equo_csrf', $csrfToken, null, '/', 'localhost', false));
        $client->request(
            'POST',
            '/api/v1/auth/refresh',
            server: [
                'HTTP_ORIGIN' => self::ORIGIN,
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
                'HTTP_X_CSRF_TOKEN' => $csrfToken,
            ],
        );
        $status = $client->getResponse()->getStatusCode();
        if (Response::HTTP_OK === $status) {
            $cookies = [];
            foreach ($client->getResponse()->headers->getCookies() as $cookie) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }

            return [
                'status' => $status,
                'code' => 'ok',
                'refresh' => (string) $cookies['equo_refresh'],
                'csrf' => (string) $cookies['__Host-equo_csrf'],
            ];
        }

        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['error'] ?? null);

        return [
            'status' => $status,
            'code' => (string) $body['error']['code'],
        ];
    }

    private function configureEnvironment(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privatePem));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        $this->setEnv('EQUO_JWT_SIGNING_KEY_VERSION', 'v1');
        $this->setEnv('EQUO_JWT_SIGNING_PRIVATE_KEY', base64_encode($privatePem));
        $this->setEnv('EQUO_JWT_PUBLIC_KEY_RING', json_encode(['v1' => base64_encode($details['key'])], JSON_THROW_ON_ERROR));
        $this->setEnv('EQUO_JWT_ISSUER', 'https://equo.test');
        $this->setEnv('EQUO_JWT_AUDIENCE', 'equo-api');
        $this->setEnv('EQUO_JWT_ACCESS_TTL_SECONDS', '900');
        $this->setEnv('EQUO_JWT_CLOCK_SKEW_SECONDS', '30');
        $this->setEnv('EQUO_CSRF_SIGNING_KEY_VERSION', 'v1');
        $this->setEnv('EQUO_CSRF_SIGNING_KEY_RING', self::CSRF_KEY_RING);
        $this->setEnv('EQUO_APPLICATION_ORIGIN', self::ORIGIN);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
