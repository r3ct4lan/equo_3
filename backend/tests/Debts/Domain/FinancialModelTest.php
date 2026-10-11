<?php

declare(strict_types=1);

namespace App\Tests\Debts\Domain;

use App\Debts\Domain\Debt;
use App\Debts\Domain\DebtParticipant;
use App\Debts\Domain\Money;
use App\Transfers\Domain\Transfer;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FinancialModelTest extends TestCase
{
    #[DataProvider('splits')]
    public function testIntegerRoundingIncludingStorageBoundary(int $amount, int $count, int $expected): void
    {
        self::assertSame($expected, (new Money($amount))->share($count));
    }

    /** @return iterable<string, array{int,int,int}> */
    public static function splits(): iterable
    {
        yield 'one cent shared by three' => [1, 3, 1];
        yield 'exact' => [6000, 3, 2000];
        yield 'rounded' => [1001, 3, 334];
        yield 'storage maximum odd' => [2147483647, 2, 1073741824];
        yield 'maximum no overflow' => [2147483647, 1, 2147483647];
    }

    public function testRoundingPropertyAndParticipantOrder(): void
    {
        for ($n = 1; $n <= 500; ++$n) {
            $amount = 1 + ($n * 4000003) % 2147483647;
            $count = 1 + $n % 47;
            $share = (new Money($amount))->share($count);
            self::assertGreaterThanOrEqual($amount, $share * $count);
            self::assertLessThanOrEqual($amount + $count - 1, $share * $count);
        }
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidMoneyRejected(int $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Money($amount);
    }

    /** @return iterable<array{int}> */
    public static function invalidAmounts(): iterable
    {
        yield [0];
        yield [-1];
        yield [2147483648];
    }

    #[DataProvider('roles')]
    public function testPayerAuthorAndSplitCombinations(bool $include, string $author, int $count): void
    {
        $now = new DateTimeImmutable('2026-10-11T12:00:00Z');
        $parts = [new DebtParticipant('p1', 'debt', 'receiver', $now), new DebtParticipant('p2', 'debt', 'removed', $now, true)];
        if ($include) {
            $parts[] = new DebtParticipant('p3', 'debt', 'payer', $now);
        }
        $debt = new Debt('debt', 'Dinner', null, new Money(1001), 'payer', $author, $now, $parts);
        self::assertSame($count, count($debt->currentParticipants()));
        self::assertSame($include, $debt->includePayerInSplit());
        self::assertSame((new Money(1001))->share($count), $debt->shareAmount());
        self::assertTrue($debt->accessibleTo('payer'));
        self::assertTrue($debt->accessibleTo('receiver'));
        self::assertFalse($debt->accessibleTo('removed'));
        self::assertFalse($debt->accessibleTo('outsider'));
    }

    /** @return iterable<array{bool,string,int}> */
    public static function roles(): iterable
    {
        yield [true, 'payer', 2];
        yield [false, 'payer', 1];
        yield [true, 'receiver', 2];
        yield [false, 'receiver', 1];
    }

    #[DataProvider('badParticipants')]
    public function testAggregateRejectsInvalidComposition(string $case): void
    {
        $now = new DateTimeImmutable();
        $p = new DebtParticipant('p', 'debt', 'receiver', $now);
        $parts = match ($case) {
            'empty' => [],
            'only payer' => [new DebtParticipant('p', 'debt', 'payer', $now)],
            'duplicate user' => [$p, new DebtParticipant('other', 'debt', 'receiver', $now)],
            'duplicate id' => [$p, new DebtParticipant('p', 'debt', 'other', $now)],
            'foreign debt' => [new DebtParticipant('p', 'foreign', 'receiver', $now)],
            'excluded author' => [$p, new DebtParticipant('q', 'debt', 'author', $now, true)],
            default => [$p],
        };
        $this->expectException(InvalidArgumentException::class);
        new Debt('debt', 'Dinner', null, new Money(100), 'payer', 'missing author' === $case || 'excluded author' === $case ? 'author' : 'payer', $now, $parts);
    }

    /** @return iterable<array{string}> */
    public static function badParticipants(): iterable
    {
        foreach (['empty', 'only payer', 'duplicate user', 'duplicate id', 'foreign debt', 'missing author', 'excluded author'] as $v) {
            yield [$v];
        }
    }

    public function testAutomaticTransferDirectionAndIndependentMembership(): void
    {
        $t = new Transfer('t', 'c', 'payer', 'receiver', 334, 'DEBT_SHARE', null, new DateTimeImmutable(), 'debt', 'p');
        $t->assertConnectSides('receiver', 'payer');
        self::assertNull($t->createdById);
        $this->expectException(InvalidArgumentException::class);
        $t->assertConnectSides('payer', 'third');
    }

    #[DataProvider('badTransfers')]
    public function testTransferLocalInvariants(string $type, string $receiver, ?string $author, ?string $debt, ?string $participant, ?string $related): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Transfer('t', 'c', 'payer', $receiver, 10, $type, $author, new DateTimeImmutable(), $debt, $participant, $related);
    }

    /** @return iterable<array{string,string,?string,?string,?string,?string}> */
    public static function badTransfers(): iterable
    {
        yield ['DEBT_SHARE', 'payer', null, 'd', 'p', null];
        yield ['DEBT_SHARE', 'receiver', 'payer', 'd', 'p', null];
        yield ['DEBT_SHARE', 'receiver', null, null, 'p', null];
        yield ['DEBT_SHARE', 'receiver', null, 'd', null, null];
        yield ['DEBT_SHARE', 'receiver', null, 'd', 'p', 'related'];
        yield ['MANUAL', 'receiver', null, null, null, null];
        yield ['MANUAL', 'receiver', 'other', null, null, null];
        yield ['MANUAL', 'receiver', 'payer', 'd', null, null];
        yield ['UNKNOWN', 'receiver', 'payer', null, null, null];
    }
}
