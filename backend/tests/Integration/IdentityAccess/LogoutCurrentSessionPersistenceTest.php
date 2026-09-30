<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineIdentityRepository;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineTransaction;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineUserSessionRepository;
use App\IdentityAccess\Adapter\System\SystemClock;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Logout\LogoutCurrentSession;
use App\IdentityAccess\Application\Logout\LogoutCurrentSessionCommand;
use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\IssuedAccessToken;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Application\Refresh\RefreshCommand;
use App\IdentityAccess\Application\Refresh\RefreshSession;
use App\IdentityAccess\Domain\Access\UserSession;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LogoutCurrentSessionPersistenceTest extends KernelTestCase
{
    private const string CSRF_KEY_RING = '{"v1":"Y3NyZi1jb25jdXJyZW5jeS10ZXN0LWtleS0zMi1ieXRlcyEhIQ=="}';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private RefreshTokenCodecPort $refreshTokenCodec;
    private CsrfTokenCodecPort $csrfTokenCodec;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->setEnv('EQUO_CSRF_SIGNING_KEY_VERSION', 'v1');
        $this->setEnv('EQUO_CSRF_SIGNING_KEY_RING', self::CSRF_KEY_RING);
        self::bootKernel();

        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->refreshTokenCodec = $container->get(RefreshTokenCodecPort::class);
        $this->csrfTokenCodec = $container->get(CsrfTokenCodecPort::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        if ($this->entityManager->isOpen()) {
            $this->entityManager->clear();
        }

        parent::tearDown();
    }

    public function testStaleCredentialAfterRefreshRevokesSessionAndBlocksCurrentCredential(): void
    {
        $userId = '87000000-0000-4000-8000-000000000001';
        $sessionId = '87000000-0000-4000-8000-000000000002';
        $oldCredential = $this->refreshTokenCodec->issueForSession($sessionId);
        $this->insertActiveUserAndSession($userId, $sessionId, $oldCredential->tokenHash);
        $csrf = $this->csrfTokenCodec->issue($sessionId);

        $refreshResult = $this->refresh()->handle(
            new RefreshCommand($oldCredential->publicToken, $csrf),
        );
        $currentCredentials = $refreshResult->withCookieSecrets(
            static fn (string $refreshToken, string $csrfToken): array => [$refreshToken, $csrfToken],
        );
        [$currentRefreshToken, $currentCsrfToken] = $currentCredentials;
        $rotatedHash = $this->field('refresh_token_hash', $sessionId);
        self::assertNotSame($oldCredential->tokenHash, $rotatedHash);

        $this->logout()->handle(
            new LogoutCurrentSessionCommand($oldCredential->publicToken, $currentCsrfToken),
        );

        self::assertNotNull($this->field('revoked_at', $sessionId));
        self::assertSame($rotatedHash, $this->field('refresh_token_hash', $sessionId));

        try {
            $this->refresh()->handle(
                new RefreshCommand($currentRefreshToken, $currentCsrfToken),
            );
            self::fail('A refresh after logout must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::InvalidRefreshToken, $failure->failureCode);
        }
    }

    public function testUnknownValidLocatorIsIdempotentNoOp(): void
    {
        $credential = $this->refreshTokenCodec->issueForSession('87000000-0000-4000-8000-000000000099');

        $this->logout()->handle(
            new LogoutCurrentSessionCommand($credential->publicToken, 'csrf-not-inspected'),
        );

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session'));
    }

    public function testFailureAfterRevokeRollsBackRevocation(): void
    {
        $userId = '87000000-0000-4000-8000-000000000011';
        $sessionId = '87000000-0000-4000-8000-000000000012';
        $credential = $this->refreshTokenCodec->issueForSession($sessionId);
        $this->insertActiveUserAndSession($userId, $sessionId, $credential->tokenHash);
        $delegate = new DoctrineUserSessionRepository($this->entityManager);
        $repository = new class($delegate) implements UserSessionRepositoryPort {
            public function __construct(private readonly UserSessionRepositoryPort $delegate)
            {
            }

            public function add(UserSession $session): void
            {
                $this->delegate->add($session);
            }

            public function findByIdForUpdate(string $sessionId): ?UserSession
            {
                return $this->delegate->findByIdForUpdate($sessionId);
            }

            public function save(UserSession $session): void
            {
                $this->delegate->save($session);

                throw new RuntimeException('Controlled persistence failure.');
            }
        };
        $now = new DateTimeImmutable('2026-08-09T12:00:00Z');
        $clock = new readonly class($now) implements ClockPort {
            public function __construct(private DateTimeImmutable $now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
        $logout = new LogoutCurrentSession(
            $repository,
            $this->refreshTokenCodec,
            $this->csrfTokenCodec,
            new DoctrineTransaction($this->entityManager),
            $clock,
        );

        try {
            $logout->handle(new LogoutCurrentSessionCommand(
                $credential->publicToken,
                $this->csrfTokenCodec->issue($sessionId),
            ));
            self::fail('The controlled persistence failure must escape.');
        } catch (RuntimeException $exception) {
            self::assertSame('Controlled persistence failure.', $exception->getMessage());
        }

        self::assertNull($this->field('revoked_at', $sessionId));
    }

    private function insertActiveUserAndSession(string $userId, string $sessionId, string $refreshTokenHash): void
    {
        $now = new DateTimeImmutable('-1 hour');
        $passwordHasher = self::getContainer()->get(PasswordHashingPort::class);
        $this->connection->insert('app_user', [
            'id' => $userId,
            'name' => 'Logout User',
            'email' => $userId.'@example.test',
            'password_hash' => $passwordHasher->hash('Correct password!'),
            'is_active' => 1,
            'created_at' => $now->format('Y-m-d H:i:sP'),
        ]);
        $this->connection->insert('user_session', [
            'id' => $sessionId,
            'user_id' => $userId,
            'refresh_token_hash' => $refreshTokenHash,
            'created_at' => $now->format('Y-m-d H:i:sP'),
            'expires_at' => $now->modify('+30 days')->format('Y-m-d H:i:sP'),
            'revoked_at' => null,
        ]);
    }

    private function refresh(): RefreshSession
    {
        return new RefreshSession(
            new DoctrineUserSessionRepository($this->entityManager),
            new DoctrineIdentityRepository($this->entityManager),
            $this->refreshTokenCodec,
            $this->csrfTokenCodec,
            new class implements AccessTokenIssuerPort {
                public function issue(string $userId): IssuedAccessToken
                {
                    return new IssuedAccessToken('access-for-'.$userId, 900);
                }
            },
            new DoctrineTransaction($this->entityManager),
            new SystemClock(),
        );
    }

    private function logout(): LogoutCurrentSession
    {
        return new LogoutCurrentSession(
            new DoctrineUserSessionRepository($this->entityManager),
            $this->refreshTokenCodec,
            $this->csrfTokenCodec,
            new DoctrineTransaction($this->entityManager),
            new SystemClock(),
        );
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function field(string $field, string $sessionId): mixed
    {
        $value = $this->connection->fetchOne('SELECT '.$field.' FROM user_session WHERE id = ?', [$sessionId]);

        return false === $value ? null : $value;
    }
}
