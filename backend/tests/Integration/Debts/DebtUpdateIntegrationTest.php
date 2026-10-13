<?php

declare(strict_types=1);

namespace App\Tests\Integration\Debts;

use App\Debts\Application\CreateDebtCommand;
use App\Debts\Application\UpdateDebt;
use App\Debts\Application\UpdateDebtCommand;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\CreateManualTransferCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DebtUpdateIntegrationTest extends KernelTestCase
{
    private Connection $db;
    private UpdateDebt $service;
    /** @var list<string> */ private array $users = [];
    /** @var array<string,mixed> */ private array $initial;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->db = $c->get(Connection::class);
        $this->service = $c->get('test.update_debt');
        $this->db->beginTransaction();
        for ($n = 0; $n < 5; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->users[] = $id;
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Update user '.$n, 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => '2026-10-12 12:00:00+00']);
        }
        foreach ([1, 2, 3] as $n) {
            $pair = [$this->users[0], $this->users[$n]];
            sort($pair);
            $this->db->insert('connect', ['id' => Uuid::v4()->toRfc4122(), 'first_user_id' => $pair[0], 'second_user_id' => $pair[1], 'created_at' => '2026-10-12 12:00:00+00']);
        }
        $this->initial = $c->get('test.create_debt')->handle(new CreateDebtCommand('Dinner', null, 1001, $this->users[1], [$this->users[0], $this->users[2]], true, Uuid::v4()->toRfc4122()), $this->users[0])['body'];
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function command(int $version = 1, int $amount = 1201): UpdateDebtCommand
    {
        return new UpdateDebtCommand($this->initial['id'], 'Updated dinner', 'Notes', $amount, [$this->users[0], $this->users[3]], false, $version);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['debt', 'debt_participant', 'connect', 'transfer', 'idempotency_record'] as $table) {
            $snapshot[$table] = $this->db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }

    public function testAtomicExcludeAddRestoreRecalculatesAllBalancesAndLeavesManualUntouched(): void
    {
        $author = $this->users[0];
        $payer = $this->users[1];
        $connect = (string) $this->db->fetchOne('SELECT connect_id FROM transfer WHERE debt_id=? AND receiver_id=?', [$this->initial['id'], $author]);
        $manual = self::getContainer()->get('test.create_manual_transfer')->handle(new CreateManualTransferCommand($connect, $author, $payer, 100, Uuid::v4()->toRfc4122()), $author)['body'];
        $manualBefore = $this->db->fetchAssociative('SELECT * FROM transfer WHERE id=?', [$manual['id']]);
        $partsBefore = array_column($this->db->fetchAllAssociative('SELECT * FROM debt_participant WHERE debt_id=?', [$this->initial['id']]), null, 'user_id');
        $sharesBefore = array_column($this->db->fetchAllAssociative('SELECT * FROM transfer WHERE debt_id=?', [$this->initial['id']]), null, 'receiver_id');
        $next = $this->service->handle($this->command(), $author);
        self::assertSame(2, $next['version']);
        self::assertSame(601, $next['shareAmount']);
        self::assertSame(2, $next['participantCount']);
        self::assertSame($payer, $next['payer']['id']);
        self::assertFalse($next['includePayerInSplit']);
        $parts = array_column($this->db->fetchAllAssociative('SELECT * FROM debt_participant WHERE debt_id=?', [$this->initial['id']]), null, 'user_id');
        $shares = array_column($this->db->fetchAllAssociative('SELECT * FROM transfer WHERE debt_id=?', [$this->initial['id']]), null, 'receiver_id');
        self::assertTrue($parts[$this->users[2]]['is_deleted']);
        self::assertTrue($parts[$payer]['is_deleted']);
        self::assertTrue($shares[$this->users[2]]['is_deleted']);
        self::assertSame(334, $shares[$this->users[2]]['amount']);
        self::assertSame(2, $shares[$this->users[2]]['version']);
        self::assertSame(601, $shares[$author]['amount']);
        self::assertSame(2, $shares[$author]['version']);
        self::assertSame(1, $shares[$this->users[3]]['version']);
        self::assertSame(-501, self::getContainer()->get('test.connect_service')->detail($connect, $author)['balanceAmount']);
        self::assertSame(501, self::getContainer()->get('test.connect_service')->detail($connect, $payer)['balanceAmount']);
        self::assertSame($manualBefore, $this->db->fetchAssociative('SELECT * FROM transfer WHERE id=?', [$manual['id']]));
        $restored = $this->service->handle(new UpdateDebtCommand($next['id'], $next['title'], null, 1201, [$author, $this->users[2], $this->users[3]], true, 2), $author);
        self::assertSame(301, $restored['shareAmount']);
        self::assertSame(3, $restored['version']);
        $restoredParts = array_column($this->db->fetchAllAssociative('SELECT * FROM debt_participant WHERE debt_id=?', [$next['id']]), null, 'user_id');
        $restoredShares = array_column($this->db->fetchAllAssociative('SELECT * FROM transfer WHERE debt_id=?', [$next['id']]), null, 'receiver_id');
        foreach ([$payer, $this->users[2]] as $id) {
            self::assertSame($partsBefore[$id]['id'], $restoredParts[$id]['id']);
            self::assertSame($partsBefore[$id]['created_at'], $restoredParts[$id]['created_at']);
            self::assertFalse($restoredParts[$id]['is_deleted']);
        }
        self::assertSame($sharesBefore[$this->users[2]]['id'], $restoredShares[$this->users[2]]['id']);
        self::assertSame($sharesBefore[$this->users[2]]['created_at'], $restoredShares[$this->users[2]]['created_at']);
        self::assertSame(3, $restoredShares[$this->users[2]]['version']);
        self::assertFalse($restoredShares[$this->users[2]]['is_deleted']);
        self::assertSame(301, $restoredShares[$this->users[2]]['amount']);
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM transfer WHERE debt_id=?', [$next['id']]));
    }

    public function testCurrentInactiveCanStayOrBeExcludedButNotRestored(): void
    {
        $author = $this->users[0];
        $inactive = $this->users[2];
        $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$inactive]);
        $kept = $this->service->handle(new UpdateDebtCommand($this->initial['id'], 'Dinner', null, 1201, [$author, $inactive], true, 1), $author);
        self::assertSame(401, $kept['shareAmount']);
        self::assertSame(3, $kept['participantCount']);
        $this->service->handle(new UpdateDebtCommand($this->initial['id'], 'Dinner', null, 1201, [$author], true, 2), $author);
        $before = $this->snapshot();
        try {
            $this->service->handle(new UpdateDebtCommand($this->initial['id'], 'Dinner', null, 1201, [$author, $inactive], true, 3), $author);
            self::fail('Inactive restoration must fail.');
        } catch (ApplicationFailure $e) {
            self::assertSame(ApplicationFailureCode::InactiveUser, $e->failureCode);
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testNoOpAndMetadataOnlyRevisionKeepShareVersions(): void
    {
        $author = $this->users[0];
        $before = $this->snapshot();
        $noop = $this->service->handle(new UpdateDebtCommand($this->initial['id'], ' Dinner ', null, 1001, [$this->users[2], $author], true, 1), $author);
        self::assertSame(1, $noop['version']);
        self::assertSame($before, $this->snapshot());
        $this->service->handle(new UpdateDebtCommand($this->initial['id'], 'Renamed', 'Notes', 1001, [$author, $this->users[2]], true, 1), $author);
        self::assertSame($before['transfer'], $this->snapshot()['transfer']);
        self::assertSame($before['debt_participant'], $this->snapshot()['debt_participant']);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT version FROM debt WHERE id=?', [$this->initial['id']]));
    }

    #[DataProvider('denials')]
    public function testDeniedUpdatesLeaveAllRowsUnchanged(string $case, ApplicationFailureCode $code): void
    {
        $author = $this->users[0];
        $actor = match ($case) {
            'foreign' => $this->users[4], 'reader' => $this->users[2], default => $author,
        };
        if ('frozen' === $case || 'author inactive' === $case) {
            $this->db->executeStatement('UPDATE app_user SET is_active=false WHERE id=?', [$this->users['author inactive' === $case ? 0 : 1]]);
        }
        if ('deleted' === $case) {
            $this->db->executeStatement('UPDATE debt SET is_deleted=true WHERE id=?', [$this->initial['id']]);
        }
        $ids = match ($case) {
            'self removal' => [$this->users[2]], 'ineligible' => [$author, $this->users[4]], default => [$author, $this->users[2]],
        };
        $before = $this->snapshot();
        try {
            $this->service->handle(new UpdateDebtCommand('missing' === $case ? Uuid::v4()->toRfc4122() : $this->initial['id'], 'Dinner', null, 1001, $ids, true, 'stale' === $case ? 2 : 1), $actor);
            self::fail('Expected denial.');
        } catch (ApplicationFailure $e) {
            self::assertSame($code, $e->failureCode);
            if ('stale' === $case) {
                self::assertSame(['expectedVersion' => 2, 'actualVersion' => 1], $e->details);
            }
        }
        self::assertSame($before, $this->snapshot());
    }

    /** @return iterable<array{string,ApplicationFailureCode}> */
    public static function denials(): iterable
    {
        yield ['foreign', ApplicationFailureCode::DebtNotAvailable];
        yield ['missing', ApplicationFailureCode::DebtNotAvailable];
        yield ['reader', ApplicationFailureCode::Forbidden];
        yield ['frozen', ApplicationFailureCode::DebtFrozen];
        yield ['author inactive', ApplicationFailureCode::DebtFrozen];
        yield ['deleted', ApplicationFailureCode::DebtDeleted];
        yield ['stale', ApplicationFailureCode::VersionConflict];
        yield ['self removal', ApplicationFailureCode::InvalidParticipant];
        yield ['ineligible', ApplicationFailureCode::InvalidParticipant];
    }

    #[DataProvider('failureStages')]
    public function testEveryRecalculationStageRollsBackEntireAggregate(string $table, string $event): void
    {
        $before = $this->snapshot();
        $this->db->executeStatement("CREATE FUNCTION debt_update_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''controlled test failure''; END;'");
        $this->db->executeStatement('CREATE TRIGGER debt_update_test_failure BEFORE '.$event.' ON '.$table.' FOR EACH ROW EXECUTE FUNCTION debt_update_test_failure()');
        try {
            $this->service->handle($this->command(), $this->users[0]);
            self::fail('Expected atomic rollback.');
        } catch (DriverException) {
        }
        self::assertSame($before, $this->snapshot());
    }

    /** @return iterable<array{string,string}> */
    public static function failureStages(): iterable
    {
        yield ['debt', 'UPDATE'];
        yield ['debt_participant', 'UPDATE'];
        yield ['debt_participant', 'INSERT'];
        yield ['connect', 'INSERT'];
        yield ['transfer', 'UPDATE'];
        yield ['transfer', 'INSERT'];
    }
}
