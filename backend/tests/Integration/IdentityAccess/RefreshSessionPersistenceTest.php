<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineIdentityRepository;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineTransaction;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineUserSessionRepository;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\IssuedAccessToken;
use App\IdentityAccess\Application\Port\IssuedRefreshToken;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Refresh\RefreshCommand;
use App\IdentityAccess\Application\Refresh\RefreshSession;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RefreshSessionPersistenceTest extends KernelTestCase
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

    public function testSuccessfulRotationChangesOnlyRefreshHash(): void
    {
        $userId = '84000000-0000-4000-8000-000000000001';
        $sessionId = '84000000-0000-4000-8000-000000000002';
        $createdAt = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $expiresAt = $createdAt->modify('+30 days');
        $this->insertUser($userId, true);
        $this->insertSession($sessionId, $userId, $this->hash('old'), $createdAt, $expiresAt);

        $result = $this->refresh($createdAt->modify('+1 day'))->handle(new RefreshCommand('rt.old', 'csrf-ok'));

        self::assertSame('access-for-'.$userId, $result->accessToken);
        self::assertSame(900, $result->expiresIn);
        self::assertSame($this->hash('new'), $this->field('refresh_token_hash', $sessionId));
        self::assertSame($sessionId, $this->field('id', $sessionId));
        self::assertSame($userId, $this->field('user_id', $sessionId));
        self::assertSame('2026-08-08 12:00:00+00', $this->dbTime('created_at', $sessionId));
        self::assertSame('2026-09-07 12:00:00+00', $this->dbTime('expires_at', $sessionId));
        self::assertNull($this->field('revoked_at', $sessionId));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ?', [$this->hash('old')]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ?', ['rt.new']));

        $result->withCookieSecrets(static function (string $refreshToken, string $csrfToken, DateTimeImmutable $cookieExpiresAt) use ($expiresAt): void {
            self::assertSame('rt.new', $refreshToken);
            self::assertSame('csrf-new', $csrfToken);
            self::assertEquals($expiresAt, $cookieExpiresAt);
        });
    }

    public function testExpiredRevokedInactiveAndUnknownDoNotRotate(): void
    {
        /** @var array<string, array{string, string, bool, string, string, string, string|null, string, ApplicationFailureCode}> $cases */
        $cases = [
            'expired' => ['84000000-0000-4000-8000-000000000011', '84000000-0000-4000-8000-000000000111', true, $this->hash('expired'), '2026-06-01 12:00:00+00', '2026-07-01 12:00:00+00', null, 'rt.expired', ApplicationFailureCode::InvalidRefreshToken],
            'revoked' => ['84000000-0000-4000-8000-000000000012', '84000000-0000-4000-8000-000000000112', true, $this->hash('revoked'), '2026-08-08 12:00:00+00', '2026-09-07 12:00:00+00', '2026-08-09 12:00:00+00', 'rt.revoked', ApplicationFailureCode::InvalidRefreshToken],
            'inactive' => ['84000000-0000-4000-8000-000000000013', '84000000-0000-4000-8000-000000000113', false, $this->hash('inactive'), '2026-08-08 12:00:00+00', '2026-09-07 12:00:00+00', null, 'rt.inactive', ApplicationFailureCode::AccountInactive],
            'unknown' => ['84000000-0000-4000-8000-000000000014', '84000000-0000-4000-8000-000000000114', true, $this->hash('other'), '2026-08-08 12:00:00+00', '2026-09-07 12:00:00+00', null, 'rt.unknown', ApplicationFailureCode::InvalidRefreshToken],
        ];

        foreach ($cases as [$sessionId, $userId, $active, $storedHash, $createdAt, $expiresAt, $revokedAt, $publicToken, $expected]) {
            $this->insertUser($userId, $active);
            $this->insertSession(
                $sessionId,
                $userId,
                $storedHash,
                new DateTimeImmutable($createdAt),
                new DateTimeImmutable($expiresAt),
                null === $revokedAt ? null : new DateTimeImmutable($revokedAt),
            );

            try {
                $this->refresh(new DateTimeImmutable('2026-08-10T12:00:00Z'))->handle(new RefreshCommand($publicToken, 'csrf-ok'));
                self::fail('Refresh failure branch must throw.');
            } catch (ApplicationFailure $failure) {
                self::assertSame($expected, $failure->failureCode);
            }

            self::assertSame($storedHash, $this->field('refresh_token_hash', $sessionId));
        }
    }

    public function testRollbackAfterControlledFailureRestoresOldHash(): void
    {
        $userId = '84000000-0000-4000-8000-000000000021';
        $sessionId = '84000000-0000-4000-8000-000000000022';
        $this->insertUser($userId, true);
        $this->insertSession($sessionId, $userId, $this->hash('old'), new DateTimeImmutable('2026-08-08T12:00:00Z'), new DateTimeImmutable('2026-09-07T12:00:00Z'));

        try {
            $this->refresh(new DateTimeImmutable('2026-08-09T12:00:00Z'), accessTokenIssuer: new class implements AccessTokenIssuerPort {
                public function issue(string $userId): IssuedAccessToken
                {
                    throw new RuntimeException('Controlled access failure.');
                }
            })->handle(new RefreshCommand('rt.old', 'csrf-ok'));
        } catch (RuntimeException $exception) {
            self::assertSame('Controlled access failure.', $exception->getMessage());
        }

        self::assertSame($this->hash('old'), $this->field('refresh_token_hash', $sessionId));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE refresh_token_hash = ?', [$this->hash('new')]));
    }

    public function testMultipleSessionsForSameUserAreIndependent(): void
    {
        $userId = '84000000-0000-4000-8000-000000000031';
        $firstSession = '84000000-0000-4000-8000-000000000032';
        $secondSession = '84000000-0000-4000-8000-000000000033';
        $createdAt = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $expiresAt = $createdAt->modify('+30 days');
        $this->insertUser($userId, true);
        $this->insertSession($firstSession, $userId, $this->hash('old'), $createdAt, $expiresAt);
        $this->insertSession($secondSession, $userId, $this->hash('other'), $createdAt, $expiresAt);

        $this->refresh($createdAt->modify('+1 day'))->handle(new RefreshCommand('rt.old', 'csrf-ok'));

        self::assertSame($this->hash('new'), $this->field('refresh_token_hash', $firstSession));
        self::assertSame($this->hash('other'), $this->field('refresh_token_hash', $secondSession));
    }

    private function refresh(DateTimeImmutable $now, ?AccessTokenIssuerPort $accessTokenIssuer = null): RefreshSession
    {
        return new RefreshSession(
            new DoctrineUserSessionRepository($this->entityManager),
            new DoctrineIdentityRepository($this->entityManager),
            $this->refreshTokenCodec(),
            $this->csrf(),
            $accessTokenIssuer ?? new class implements AccessTokenIssuerPort {
                public function issue(string $userId): IssuedAccessToken
                {
                    return new IssuedAccessToken('access-for-'.$userId, 900);
                }
            },
            new DoctrineTransaction($this->entityManager),
            new readonly class($now) implements ClockPort {
                public function __construct(private DateTimeImmutable $now)
                {
                }

                public function now(): DateTimeImmutable
                {
                    return $this->now;
                }
            },
        );
    }

    private function refreshTokenCodec(): RefreshTokenCodecPort
    {
        return new class implements RefreshTokenCodecPort {
            public function issue(): IssuedRefreshToken
            {
                return new IssuedRefreshToken('rt.new', 'sha256:'.hash('sha256', 'new'));
            }

            public function digest(string $publicToken): ?string
            {
                return match ($publicToken) {
                    'rt.old' => 'sha256:'.hash('sha256', 'old'),
                    'rt.expired' => 'sha256:'.hash('sha256', 'expired'),
                    'rt.revoked' => 'sha256:'.hash('sha256', 'revoked'),
                    'rt.inactive' => 'sha256:'.hash('sha256', 'inactive'),
                    'rt.unknown' => 'sha256:'.hash('sha256', 'unknown'),
                    default => null,
                };
            }
        };
    }

    private function csrf(): CsrfTokenCodecPort
    {
        return new class implements CsrfTokenCodecPort {
            public function issue(string $sessionId): string
            {
                return 'csrf-new';
            }

            public function verify(string $sessionId, string $publicToken): bool
            {
                return 'csrf-ok' === $publicToken;
            }
        };
    }

    private function insertUser(string $id, bool $active): void
    {
        $this->connection->insert('app_user', [
            'id' => $id,
            'name' => 'Refresh User',
            'email' => $id.'@example.test',
            'password_hash' => $this->passwordHasher->hash('Correct password!'),
            'is_active' => $active ? 1 : 0,
            'created_at' => '2026-08-08 12:00:00+00',
        ]);
    }

    private function insertSession(
        string $id,
        string $userId,
        string $refreshTokenHash,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $revokedAt = null,
    ): void {
        $this->connection->insert('user_session', [
            'id' => $id,
            'user_id' => $userId,
            'refresh_token_hash' => $refreshTokenHash,
            'created_at' => $createdAt->format('Y-m-d H:i:sP'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:sP'),
            'revoked_at' => $revokedAt?->format('Y-m-d H:i:sP'),
        ]);
    }

    private function field(string $field, string $sessionId): mixed
    {
        $value = $this->connection->fetchOne('SELECT '.$field.' FROM user_session WHERE id = ?', [$sessionId]);

        return false === $value ? null : $value;
    }

    private function dbTime(string $field, string $sessionId): string
    {
        $value = $this->connection->fetchOne("SELECT to_char($field, 'YYYY-MM-DD HH24:MI:SSOF') FROM user_session WHERE id = ?", [$sessionId]);
        self::assertIsString($value);

        return $value;
    }

    private function hash(string $seed): string
    {
        return 'sha256:'.hash('sha256', $seed);
    }
}
