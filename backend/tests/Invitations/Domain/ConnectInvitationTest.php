<?php

declare(strict_types=1);

namespace App\Tests\Invitations\Domain;

use App\Invitations\Domain\ConnectInvitation;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConnectInvitationTest extends TestCase
{
    public function testExpiryBoundaryAndSingleUse(): void
    {
        $now = new DateTimeImmutable('2026-10-10T12:00:00Z');
        $i = new ConnectInvitation('invitation', 'author', 'hash', $now, $now->modify('+7 days'));
        self::assertTrue($i->isAvailable($now));
        self::assertFalse($i->isAvailable($now->modify('+7 days')));
        $i->accept('acceptor', $now);
        self::assertSame('acceptor', $i->acceptedById);
        self::assertSame($now, $i->usedAt);
        self::assertFalse($i->isAvailable($now));
        $this->expectException(InvalidArgumentException::class);
        $i->accept('someone-else', $now);
    }

    public function testSelfAcceptanceIsRejected(): void
    {
        $now = new DateTimeImmutable();
        $i = new ConnectInvitation('invitation', 'author', 'hash', $now, $now->modify('+7 days'));
        $this->expectException(InvalidArgumentException::class);
        $i->accept('author', $now);
    }
}
