<?php

declare(strict_types=1);

namespace App\Tests\Transfers\Domain;

use App\Transfers\Domain\Transfer;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ShareRevisionTest extends TestCase
{
    public function testShareChangesPreserveIdentityAndOriginalDateWithActualVersions(): void
    {
        $t = new Transfer('share', 'connect', 'payer', 'other', 501, 'DEBT_SHARE', null, new DateTimeImmutable(), 'debt', 'participant');
        self::assertSame($t, $t->recalculateShare(501, false));
        $deleted = $t->recalculateShare(501, true);
        self::assertSame(2, $deleted->version);
        $restored = $deleted->recalculateShare(334, false);
        self::assertSame(3, $restored->version);
        self::assertSame($t->id, $restored->id);
        self::assertSame($t->createdAt, $restored->createdAt);
        self::assertSame($t->debtParticipantId, $restored->debtParticipantId);
        self::assertFalse($restored->isDeleted);
    }

    public function testManualCannotBeRecalculated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Transfer::manual('m', 'c', 'a', 'b', 100, 'a', new DateTimeImmutable())->recalculateShare(200, false);
    }
}
