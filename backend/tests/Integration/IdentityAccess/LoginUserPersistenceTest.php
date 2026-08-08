<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineIdentityRepository;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineTransaction;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineUserSessionRepository;
use App\IdentityAccess\Adapter\System\SystemClock;
use App\IdentityAccess\Application\Login\LoginCommand;
use App\IdentityAccess\Application\Login\LoginUser;
use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\IssuedAccessToken;
use App\IdentityAccess\Application\Port\IssuedRefreshToken;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Port\UuidPort;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

final class LoginUserPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private PasswordHashingPort $passwordHasher;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->passwordHasher = self::getContainer()->get(PasswordHashingPort::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->entityManager->clear();

        parent::tearDown();
    }

    public function testActiveUserLoginCreatesOneHashOnlySession(): void
    {
        $userId = '82000000-0000-4000-8000-000000000001';
        $this->insertUser($userId, 'login@example.test', true, 'Correct password!');

        $result = $this->login()->handle(new LoginCommand('  Login@Example.Test  ', 'Correct password!', '192.0.2.1'));

        self::assertSame('access-for-'.$userId, $result->accessToken);
        self::assertSame(900, $result->expiresIn);
        self::assertSame('login@example.test', $result->user->email);
        self::assertSame(1, $this->countSessions());
        self::assertSame($userId, $this->connection->fetchOne('SELECT user_id FROM user_session'));
        self::assertSame('sha256:'.hash('sha256', 'refresh-1'), $this->connection->fetchOne('SELECT refresh_token_hash FROM user_session'));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_session WHERE refresh_token_hash LIKE ?',
            ['rt.%'],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ?',
            ['rt.refresh-1'],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ?',
            [$result->accessToken],
        ));
    }

    public function testInactiveWrongAndUnknownUsersDoNotCreateSessions(): void
    {
        $this->insertUser('82000000-0000-4000-8000-000000000011', 'inactive@example.test', false, 'Correct password!');
        $this->insertUser('82000000-0000-4000-8000-000000000012', 'wrong@example.test', true, 'Correct password!');

        foreach ([
            new LoginCommand('inactive@example.test', 'Correct password!', '192.0.2.11'),
            new LoginCommand('wrong@example.test', 'Wrong password!', '192.0.2.12'),
            new LoginCommand('unknown@example.test', 'Any password!', '192.0.2.13'),
        ] as $command) {
            try {
                $this->login()->handle($command);
            } catch (Throwable) {
            }
        }

        self::assertSame(0, $this->countSessions());
    }

    public function testTwoSuccessfulLoginsCreateDistinctSessions(): void
    {
        $userId = '82000000-0000-4000-8000-000000000021';
        $this->insertUser($userId, 'multi@example.test', true, 'Correct password!');
        $login = $this->login();

        $login->handle(new LoginCommand('multi@example.test', 'Correct password!', '192.0.2.21'));
        $login->handle(new LoginCommand('multi@example.test', 'Correct password!', '192.0.2.22'));

        self::assertSame(2, $this->countSessions());
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT id) FROM user_session'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT refresh_token_hash) FROM user_session'));
    }

    public function testFailureAfterPendingSessionRollsBack(): void
    {
        $userId = '82000000-0000-4000-8000-000000000031';
        $this->insertUser($userId, 'rollback-login@example.test', true, 'Correct password!');

        try {
            $this->login(accessTokenIssuer: new class implements AccessTokenIssuerPort {
                public function issue(string $userId): IssuedAccessToken
                {
                    throw new RuntimeException('Controlled access token failure.');
                }
            })->handle(new LoginCommand('rollback-login@example.test', 'Correct password!', '192.0.2.31'));
        } catch (RuntimeException $exception) {
            self::assertSame('Controlled access token failure.', $exception->getMessage());
        }

        self::assertSame(0, $this->countSessions());
    }

    private function login(?AccessTokenIssuerPort $accessTokenIssuer = null): LoginUser
    {
        return new LoginUser(
            new DoctrineIdentityRepository($this->entityManager),
            new DoctrineUserSessionRepository($this->entityManager),
            $this->passwordHasher,
            $accessTokenIssuer ?? new class implements AccessTokenIssuerPort {
                public function issue(string $userId): IssuedAccessToken
                {
                    return new IssuedAccessToken('access-for-'.$userId, 900);
                }
            },
            $this->refreshTokenCodec(),
            new class implements CsrfTokenCodecPort {
                public function issue(string $sessionId): string
                {
                    return 'csrf-for-'.$sessionId;
                }

                public function verify(string $sessionId, string $publicToken): bool
                {
                    return true;
                }
            },
            $this->rateLimit(),
            new DoctrineTransaction($this->entityManager),
            new SystemClock(),
            $this->uuid(),
        );
    }

    private function refreshTokenCodec(): RefreshTokenCodecPort
    {
        return new class implements RefreshTokenCodecPort {
            private int $counter = 0;

            public function issue(): IssuedRefreshToken
            {
                ++$this->counter;

                return new IssuedRefreshToken(
                    'rt.refresh-'.$this->counter,
                    'sha256:'.hash('sha256', 'refresh-'.$this->counter),
                );
            }

            public function digest(string $publicToken): ?string
            {
                if ('' === $publicToken) {
                    return null;
                }

                return 'sha256:'.hash('sha256', $publicToken);
            }
        };
    }

    private function rateLimit(): RateLimitPort
    {
        return new class implements RateLimitPort {
            public function registrationRetryAfter(string $ip, string $normalizedEmail): ?int
            {
                return null;
            }

            public function activationRetryAfter(string $ip, string $tokenFingerprint): ?int
            {
                return null;
            }

            public function loginRetryAfter(string $ip, string $normalizedEmail): ?int
            {
                return null;
            }
        };
    }

    private function uuid(): UuidPort
    {
        return new class implements UuidPort {
            private int $counter = 0;

            public function generate(): string
            {
                ++$this->counter;

                return '82000000-0000-4000-8000-'.sprintf('%012d', 100 + $this->counter);
            }
        };
    }

    private function insertUser(string $id, string $email, bool $active, string $password): void
    {
        $this->connection->insert('app_user', [
            'id' => $id,
            'name' => 'Login User',
            'email' => $email,
            'password_hash' => $this->passwordHasher->hash($password),
            'is_active' => $active ? 1 : 0,
            'created_at' => (new DateTimeImmutable('2026-08-08T12:00:00Z'))->format('Y-m-d H:i:sP'),
        ]);
    }

    private function countSessions(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session');
    }
}
