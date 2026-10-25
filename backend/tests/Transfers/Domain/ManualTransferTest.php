<?php

declare(strict_types=1);

namespace App\Tests\Transfers\Domain;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\Transfers\Application\CreateManualTransferCommand;
use App\Transfers\Domain\ManualTransferFailure;
use App\Transfers\Domain\ManualTransferRejected;
use App\Transfers\Domain\Transfer;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManualTransferTest extends TestCase
{
    public function testFactoryAllowsEitherDirectionAndAuthorWithNoDebtOrBalanceCap(): void
    {
        foreach ([['a', 'b', 'a'], ['b', 'a', 'a'], ['a', 'b', 'b'], ['b', 'a', 'b']] as [$sender, $receiver, $author]) {
            $t = Transfer::manual('id', 'connect', $sender, $receiver, 2147483647, $author, new DateTimeImmutable());
            $t->assertConnectSides('a', 'b');
            self::assertSame('MANUAL', $t->type);
            self::assertSame($author, $t->createdById);
            self::assertSame(2147483647, $t->amount);
            self::assertSame(1, $t->version);
            self::assertFalse($t->isDeleted);
            self::assertNull($t->debtId);
            self::assertNull($t->debtParticipantId);
            self::assertNull($t->relatedTransferId);
        }
    }

    #[DataProvider('invalid')]
    public function testFactoryRejectsInvalidMoneySidesOrAuthor(int $amount, string $sender, string $receiver, string $author): void
    {
        $this->expectException(InvalidArgumentException::class);
        Transfer::manual('id', 'connect', $sender, $receiver, $amount, $author, new DateTimeImmutable())->assertConnectSides('a', 'b');
    }

    /** @return iterable<array{int,string,string,string}> */
    public static function invalid(): iterable
    {
        yield [0, 'a', 'b', 'a'];
        yield [-1, 'a', 'b', 'a'];
        yield [2147483648, 'a', 'b', 'a'];
        yield [1, 'a', 'a', 'a'];
        yield [1, 'a', 'b', 'c'];
        yield [1, 'a', 'c', 'a'];
    }

    public function testCanonicalIncludesTargetAndNormalizesUuids(): void
    {
        $a = 'AAAAAAAA-0000-4000-8000-000000000001';
        $b = 'bbbbbbbb-0000-4000-8000-000000000002';
        $c = 'cccccccc-0000-4000-8000-000000000003';
        $key = 'dddddddd-0000-4000-8000-000000000004';
        $command = new CreateManualTransferCommand($c, $a, $b, 1, $key);
        self::assertSame((new CreateManualTransferCommand($c, strtolower($a), $b, 1, strtoupper($key)))->canonical(), $command->canonical());
        self::assertNotSame((new CreateManualTransferCommand($key, $a, $b, 1, $key))->canonical(), $command->canonical());
        $this->expectException(ApplicationFailure::class);
        new CreateManualTransferCommand($c, $a, strtolower($a), 1, $key);
    }

    public function testManualRevisionChangesDirectionAndAmountAndRemovalPreservesHistory(): void
    {
        $original = Transfer::manual('id', 'connect', 'a', 'b', 100, 'a', new DateTimeImmutable('2026-10-25T00:00:00Z'));
        self::assertSame($original, $original->reviseManual('a', 'b', 100, 'a', 1, true));
        $changed = $original->reviseManual('b', 'a', 250, 'a', 1, true);
        self::assertSame('b', $changed->senderId);
        self::assertSame('a', $changed->receiverId);
        self::assertSame(250, $changed->amount);
        self::assertSame(2, $changed->version);
        self::assertSame($original->createdAt, $changed->createdAt);
        $removed = $changed->removeManual('a', 2, true);
        self::assertTrue($removed->isDeleted);
        self::assertSame(3, $removed->version);
        self::assertSame(250, $removed->amount);
        self::assertSame('b', $removed->senderId);
    }

    /** @param callable(Transfer):void $operation */
    #[DataProvider('manualChangeDenials')]
    public function testManualChangeDenials(Transfer $transfer, callable $operation, ManualTransferFailure $failure): void
    {
        try {
            $operation($transfer);
            self::fail('Expected manual transfer denial.');
        } catch (ManualTransferRejected $e) {
            self::assertSame($failure, $e->reason);
        }
    }

    /** @return iterable<array{Transfer,callable(Transfer):void,ManualTransferFailure}> */
    public static function manualChangeDenials(): iterable
    {
        $time = new DateTimeImmutable('2026-10-25T00:00:00Z');
        $manual = Transfer::manual('id', 'connect', 'a', 'b', 100, 'a', $time);
        yield [new Transfer('share', 'connect', 'a', 'b', 100, 'DEBT_SHARE', null, $time, 'debt', 'participant'), static function (Transfer $t): void { $t->removeManual('a', 1, true); }, ManualTransferFailure::Automatic];
        yield [$manual, static function (Transfer $t): void { $t->removeManual('b', 1, true); }, ManualTransferFailure::Forbidden];
        yield [new Transfer('id', 'connect', 'a', 'b', 100, 'MANUAL', 'a', $time, isDeleted: true), static function (Transfer $t): void { $t->removeManual('a', 1, true); }, ManualTransferFailure::Deleted];
        yield [$manual, static function (Transfer $t): void { $t->removeManual('a', 1, false); }, ManualTransferFailure::Frozen];
        yield [$manual, static function (Transfer $t): void { $t->removeManual('a', 2, true); }, ManualTransferFailure::VersionConflict];
        yield [$manual, static function (Transfer $t): void { $t->reviseManual('a', 'c', 100, 'a', 1, true); }, ManualTransferFailure::InvalidSides];
    }
}
