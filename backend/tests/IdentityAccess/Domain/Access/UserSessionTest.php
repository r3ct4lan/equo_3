<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Domain\Access;

use App\IdentityAccess\Domain\Access\UserSession;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserSessionTest extends TestCase
{
    public function testCreateUsesThirtyDayTtlAndActiveLifecycle(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $session = UserSession::create(
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            $this->hash('a'),
            $createdAt,
        );

        self::assertSame($this->hash('a'), $session->refreshTokenHash());
        self::assertEquals($createdAt->modify('+30 days'), $session->expiresAt);
        self::assertTrue($session->isActive($createdAt->modify('+29 days')));
        self::assertFalse($session->isActive($createdAt->modify('+30 days')));
    }

    public function testRotateChangesOnlyActiveSessionHash(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $session = UserSession::create(
            '00000000-0000-4000-8000-000000000011',
            '00000000-0000-4000-8000-000000000012',
            $this->hash('a'),
            $createdAt,
        );

        $session->rotate($this->hash('b'), $createdAt->modify('+1 hour'));

        self::assertSame($this->hash('b'), $session->refreshTokenHash());
        self::assertNull($session->revokedAt());
        self::assertEquals($createdAt, $session->createdAt);
        self::assertEquals($createdAt->modify('+30 days'), $session->expiresAt);
    }

    public function testExpiredOrRevokedSessionCannotRotate(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $expired = UserSession::create(
            '00000000-0000-4000-8000-000000000021',
            '00000000-0000-4000-8000-000000000022',
            $this->hash('a'),
            $createdAt,
        );

        try {
            $expired->rotate($this->hash('b'), $createdAt->modify('+30 days'));
            self::fail('Expired sessions must not rotate.');
        } catch (DomainException) {
            self::assertSame($this->hash('a'), $expired->refreshTokenHash());
        }

        $revoked = UserSession::create(
            '00000000-0000-4000-8000-000000000023',
            '00000000-0000-4000-8000-000000000024',
            $this->hash('c'),
            $createdAt,
        );
        $revoked->revoke($createdAt->modify('+1 hour'));

        try {
            $revoked->rotate($this->hash('d'), $createdAt->modify('+2 hours'));
            self::fail('Revoked sessions must not rotate.');
        } catch (DomainException) {
            self::assertSame($this->hash('c'), $revoked->refreshTokenHash());
        }
    }

    public function testRevokeIsIdempotentAndKeepsFirstTimestamp(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-08T12:00:00Z');
        $firstRevokedAt = $createdAt->modify('+1 hour');
        $session = UserSession::create(
            '00000000-0000-4000-8000-000000000025',
            '00000000-0000-4000-8000-000000000026',
            $this->hash('revoke'),
            $createdAt,
        );

        $session->revoke($firstRevokedAt);
        $session->revoke($createdAt->modify('+2 hours'));

        self::assertSame($firstRevokedAt, $session->revokedAt());
        self::assertFalse($session->isActive($createdAt->modify('+90 minutes')));
    }

    public function testInvalidHashFormatIsRejectedWithoutEchoingTheSecret(): void
    {
        try {
            UserSession::create(
                '00000000-0000-4000-8000-000000000031',
                '00000000-0000-4000-8000-000000000032',
                'rt.plaintext-refresh-token',
                new DateTimeImmutable('2026-08-08T12:00:00Z'),
            );
            self::fail('Plain refresh tokens must not be accepted as storage values.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('rt.plaintext-refresh-token', $exception->getMessage());
        }
    }

    private function hash(string $seed): string
    {
        return 'sha256:'.hash('sha256', $seed);
    }
}
