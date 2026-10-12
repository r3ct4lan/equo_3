<?php

declare(strict_types=1);

namespace App\Tests\Debts\Domain;

use App\Debts\Domain\Debt;
use App\Debts\Domain\DebtParticipant;
use App\Debts\Domain\DebtUpdate;
use App\Debts\Domain\DebtUpdateFailure;
use App\Debts\Domain\DebtUpdateRejected;
use App\Debts\Domain\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DebtRevisionTest extends TestCase
{
    private function debt(string $author = 'payer', bool $deleted = false): Debt
    {
        $time = new DateTimeImmutable('2026-10-11T12:00:00Z');

        return new Debt('debt', 'Dinner', null, new Money(1001), 'payer', $author, $time, [
            new DebtParticipant('p0', 'debt', 'payer', $time),
            new DebtParticipant('p1', 'debt', 'a', $time),
            new DebtParticipant('p2', 'debt', 'b', $time),
            new DebtParticipant('p3', 'debt', 'c', $time, true),
        ], $deleted);
    }

    public function testFullRecalculationRetainsExcludedAndRestoredIdentityAndPayer(): void
    {
        $debt = $this->debt();
        $now = new DateTimeImmutable('2026-10-12T12:00:00Z');
        $activity = ['payer' => true, 'a' => true, 'b' => false, 'c' => true, 'new' => true];
        $next = $debt->revise(new DebtUpdate(' Changed ', 'Notes', new Money(1201), ['a', 'c', 'new'], false), 'payer', 1, $activity, $activity, static fn (): string => 'p4', $now);
        self::assertSame('Changed', $next->title);
        self::assertSame(2, $next->version);
        self::assertSame('payer', $next->payerId);
        self::assertSame($debt->createdAt, $next->createdAt);
        self::assertSame(401, $next->shareAmount());
        $parts = array_column($next->participants, null, 'userId');
        self::assertTrue($parts['payer']->isDeleted);
        self::assertTrue($parts['b']->isDeleted);
        self::assertFalse($parts['c']->isDeleted);
        self::assertSame('p3', $parts['c']->id);
        self::assertSame($debt->createdAt, $parts['c']->createdAt);
        self::assertSame($now, $parts['new']->createdAt);
        self::assertSame('p4', $parts['new']->id);
        $restored = $next->revise(new DebtUpdate('Changed', 'Notes', new Money(1201), ['a', 'b', 'c'], true), 'payer', 2, array_fill_keys(['payer', 'a', 'b', 'c'], true), array_fill_keys(['payer', 'a', 'b', 'c'], true), static fn (): string => 'unused', $now);
        $parts = array_column($restored->participants, null, 'userId');
        self::assertSame('p2', $parts['b']->id);
        self::assertSame('p0', $parts['payer']->id);
        self::assertTrue($parts['new']->isDeleted);
        self::assertSame(301, $restored->shareAmount());
        self::assertSame(3, $restored->version);
    }

    public function testInactiveCurrentCanStayAndMetadataChangeDoesNotAlterComposition(): void
    {
        $debt = $this->debt();
        $next = $debt->revise(new DebtUpdate('Dinner', 'Updated notes', new Money(1001), ['b', 'a'], true), 'payer', 1, ['payer' => true, 'a' => true, 'b' => false], [], static fn (): string => 'unused', $debt->createdAt);
        self::assertSame(3, count($next->currentParticipants()));
        self::assertSame(334, $next->shareAmount());
        self::assertSame(2, $next->version);
        self::assertSame(['p0', 'p1', 'p2'], array_map(static fn (DebtParticipant $p): string => $p->id, $next->currentParticipants()));
    }

    public function testNormalizedNoOpPreservesEntireAggregateAndDoesNotAllocateIds(): void
    {
        $debt = $this->debt();
        $next = $debt->revise(new DebtUpdate(' Dinner ', null, new Money(1001), ['b', 'a'], true), 'payer', 1, ['payer' => true], [], static function (): string { self::fail('No-op must not allocate rows.'); }, $debt->createdAt);
        self::assertSame($debt, $next);
    }

    #[DataProvider('denials')]
    public function testStateVersionAndCompositionDenials(string $case, DebtUpdateFailure $failure): void
    {
        $debt = $this->debt('self removal' === $case ? 'a' : 'payer', 'deleted' === $case);
        $actor = 'foreign' === $case ? 'a' : $debt->createdById;
        $version = 'stale' === $case ? 2 : 1;
        $ids = match ($case) {
            'empty' => [],
            'duplicate' => ['a', 'a'],
            'payer in ids' => ['a', 'payer'],
            'self removal' => ['b'],
            'inactive restored', 'ineligible' => ['a', 'c'],
            default => ['a', 'b'],
        };
        $active = array_fill_keys(['payer', 'a', 'b', 'c'], true);
        if ('inactive author' === $case) {
            $active[$debt->createdById] = false;
        }
        if ('inactive payer' === $case) {
            $active['payer'] = false;
        }
        if ('inactive restored' === $case) {
            $active['c'] = false;
        }
        $eligible = array_fill_keys(['payer', 'a', 'b', 'c'], true);
        if ('ineligible' === $case) {
            $eligible['c'] = false;
        }
        try {
            $debt->revise(new DebtUpdate('Dinner', null, new Money(1001), $ids, true), $actor, $version, $active, $eligible, static fn (): string => 'unused', $debt->createdAt);
            self::fail('Expected domain denial.');
        } catch (DebtUpdateRejected $e) {
            self::assertSame($failure, $e->reason);
        }
        self::assertSame(1, $debt->version);
    }

    /** @return iterable<array{string,DebtUpdateFailure}> */
    public static function denials(): iterable
    {
        yield ['foreign', DebtUpdateFailure::Forbidden];
        yield ['deleted', DebtUpdateFailure::Deleted];
        yield ['inactive author', DebtUpdateFailure::Frozen];
        yield ['inactive payer', DebtUpdateFailure::Frozen];
        yield ['stale', DebtUpdateFailure::VersionConflict];
        foreach (['empty', 'duplicate', 'payer in ids', 'self removal', 'ineligible'] as $case) {
            yield [$case, DebtUpdateFailure::InvalidParticipant];
        }
        yield ['inactive restored', DebtUpdateFailure::InactiveParticipant];
    }
}
