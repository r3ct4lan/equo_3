<?php

declare(strict_types=1);

namespace App\Tests\Transfers\Domain;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\Transfers\Application\CreateManualTransferCommand;
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
}
