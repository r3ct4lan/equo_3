<?php

declare(strict_types=1);

namespace App\Tests\Debts;

use App\Debts\Adapter\ExactReceiptArithmetic;
use App\Debts\Domain\ReceiptSplit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptSplitTest extends TestCase
{
    /** @return iterable<string,array{int,list<array<string,mixed>>,int,array<string,mixed>,array<string,int>}> */
    public static function cases(): iterable
    {
        $two = ['a' => null, 'b' => null];
        $three = [...$two, 'c' => null];
        $row = static fn (int $amount, array $assigned, string $quantity = '1'): array => ['name' => 'item', 'quantity' => $quantity, 'amount' => $amount, 'assignments' => $assigned];
        yield 'pizza' => [1200, [$row(1200, $two)], 0, [], ['a' => 600, 'b' => 600]];
        yield 'three coffees' => [1200, [$row(1200, ['a' => '1', 'b' => '2'], '3')], 0, [], ['a' => 400, 'b' => 800]];
        yield 'missing row' => [3000, [$row(2600, $two)], 0, ['a' => null], ['a' => 1700, 'b' => 1300]];
        yield 'water reconciled' => [3000, [$row(2600, $two), $row(150, ['b' => null])], 0, ['a' => null], ['a' => 1550, 'b' => 1450]];
        yield 'confirmed discount' => [2400, [$row(2000, ['a' => null]), $row(1000, ['b' => null])], 600, [], ['a' => 1600, 'b' => 800]];
        yield 'round only once' => [300, [$row(100, $three), $row(200, $three)], 0, [], array_fill_keys(array_keys($three), 100)];
        yield 'EUR JPY KWD minor units' => [1000, [$row(1000, $three)], 0, [], array_fill_keys(array_keys($three), 334)];
    }

    /** @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $remainder
     * @param array<string,int> $expected
     */
    #[DataProvider('cases')]
    public function testExactShares(int $total, array $rows, int $discount, array $remainder, array $expected): void
    {
        self::assertSame($expected, (new ReceiptSplit(new ExactReceiptArithmetic()))->calculate($total, $rows, $discount, $remainder, ['a', 'b', 'c']));
    }

    public function testIncompleteOrExcessAssignmentsAndUnconfirmedDiscountAreRejected(): void
    {
        foreach ([[100, [], []], [101, ['a' => null], []], [99, ['a' => null], []], [100, ['a' => '1'], []], [100, ['a' => '3'], []], [100, ['outside' => null], []]] as [$total, $assignments, $remainder]) {
            try {
                (new ReceiptSplit(new ExactReceiptArithmetic()))->calculate($total, [['quantity' => '2', 'amount' => 100, 'assignments' => $assignments]], 0, $remainder, ['a']);
                self::fail('Invalid allocation accepted');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
