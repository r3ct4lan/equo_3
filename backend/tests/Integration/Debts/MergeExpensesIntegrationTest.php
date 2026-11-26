<?php

declare(strict_types=1);

namespace App\Tests\Integration\Debts;

use App\Debts\Adapter\Persistence\Doctrine\DoctrineDebtRepository;
use App\Debts\Adapter\Persistence\Doctrine\DoctrineMergeExpenseRepository;
use App\Debts\Application\CreateDebtCommand;
use App\Debts\Application\MergeExpenses;
use App\Debts\Application\UpdateDebtCommand;
use App\Transfers\Adapter\Persistence\Doctrine\DoctrineTransferRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class MergeExpensesIntegrationTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }

        parent::tearDown();
    }

    public function testMergeKeepsLedgerSharesThroughDescriptiveEditsAndRecalculatesOnFinancialEdit(): void
    {
        [$primary, $joined, $payer] = $this->people(3);
        $first = $this->createExpense($payer, [$primary, $joined], true, 6000);
        $internal = $this->createExpense($primary, [$joined], false, 2000);
        $firstId = $first['id'];
        $internalId = $internal['id'];
        self::assertIsString($firstId);
        self::assertIsString($internalId);
        $primaryShare = $this->db->fetchOne('SELECT id FROM transfer WHERE debt_id = ? AND receiver_id = ?', [$firstId, $primary]);
        $joinedShare = $this->db->fetchOne('SELECT id FROM transfer WHERE debt_id = ? AND receiver_id = ?', [$firstId, $joined]);
        self::assertIsString($primaryShare);
        self::assertIsString($joinedShare);

        $connectId = (string) $this->db->fetchOne('SELECT connect_id FROM transfer WHERE id=?', [$joinedShare]);
        $payment = self::getContainer()->get('test.create_manual_transfer')->handle(new \App\Transfers\Application\CreateManualTransferCommand($connectId, $joined, $payer, 700, Uuid::v4()->toRfc4122(), $joinedShare), $joined)['body'];
        $debtRepository = new DoctrineDebtRepository($this->db);
        $transfers = new DoctrineTransferRepository($this->db, self::getContainer()->get(\App\Transfers\Application\Api\ReconcileShare::class));
        $merge = new MergeExpenses($debtRepository, new DoctrineMergeExpenseRepository($this->db, self::getContainer()->get(\App\Transfers\Application\Api\ReconcileShare::class)));
        $merge->apply($primary, $joined);

        self::assertSame(4000, $transfers->find($primaryShare)?->amount);
        self::assertNull($transfers->find($joinedShare));
        self::assertSame(700, $transfers->find($primaryShare)->creditedAmount);
        self::assertSame($primaryShare, $transfers->find($payment['id'])?->relatedTransferId);
        self::assertNull($debtRepository->find($internalId));
        self::assertSame(0, count($transfers->sharesForDebt($internalId)));

        $updates = self::getContainer()->get('test.update_debt');
        $renamed = $updates->handle(new UpdateDebtCommand($firstId, 'Renamed', null, 6000, [$primary], true, 2), $payer);
        self::assertNull($renamed['shareAmount']);
        self::assertSame(4000, $transfers->find($primaryShare)->amount);
        $recalculated = $updates->handle(new UpdateDebtCommand($firstId, 'Renamed', null, 9000, [$primary], true, 3), $payer);
        self::assertSame(4500, $recalculated['shareAmount']);
        self::assertSame(4500, $transfers->find($primaryShare)->amount);
    }

    public function testMergedReceiptKeepsTwoRoundedSharesAndTransfersTheirAssignments(): void
    {
        [$primary, $joined, $payer] = $this->people(3);
        $storage = new \App\Debts\Adapter\Persistence\Doctrine\DoctrineReceiptStorage($this->db);
        $key = Uuid::v4()->toRfc4122();
        $upload = $storage->prepare($payer, $key, ['data:image/png;base64,'.base64_encode((string) file_get_contents(__DIR__.'/../../Debts/receipts/water-1.png'))]);
        $receipt = ['uploadId' => $upload, 'discount' => 0, 'remainder' => [], 'lines' => [['name' => 'Water', 'quantity' => '1', 'unitPrice' => 100, 'amount' => 100, 'assignments' => [$primary => null, $joined => null, $payer => null]]]];
        $created = self::getContainer()->get('test.create_debt')->handle(new CreateDebtCommand('Lunch', null, 100, $payer, [$primary, $joined], true, $key, 'EUR', receipt: $receipt), $payer)['body'];
        $repo = new DoctrineDebtRepository($this->db);
        $merge = new MergeExpenses($repo, new DoctrineMergeExpenseRepository($this->db, self::getContainer()->get(\App\Transfers\Application\Api\ReconcileShare::class)));
        $merge->apply($primary, $joined);
        $saved = $storage->saved($created['id']);
        self::assertIsArray($saved);
        self::assertArrayNotHasKey($joined, $saved['lines'][0]['assignments']);
        self::assertSame(2, $saved['lines'][0]['mergedSeats'][$primary]);
        $saved['lines'][0]['name'] = 'Mineralwasser';
        $next = self::getContainer()->get('test.update_debt')->handle(new UpdateDebtCommand($created['id'], 'Lunch', null, 100, [$primary], true, 2, receipt: $saved), $payer);
        $member = array_values(array_filter($next['participants'], static fn (array $p): bool => $p['user']['id'] === $primary))[0];
        self::assertSame(68, $member['shareAmount']);
        self::assertSame(68, $member['remainingAmount']);
    }

    /** @return list<string> */
    private function people(int $count): array
    {
        $ids = [];
        $date = '2026-10-12 12:00:00+00';
        for ($i = 0; $i < $count; ++$i) {
            $id = Uuid::v4()->toRfc4122();
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Merge user '.$i, 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => $date]);
            $ids[] = $id;
        }
        sort($ids, SORT_STRING);
        foreach ($ids as $i => $first) {
            foreach (array_slice($ids, $i + 1) as $second) {
                $this->db->insert('connect', ['id' => Uuid::v4()->toRfc4122(), 'first_user_id' => $first, 'second_user_id' => $second, 'created_at' => $date]);
            }
        }

        return $ids;
    }

    /** @param list<string> $participants
     * @return array<string, mixed>
     */
    private function createExpense(string $payer, array $participants, bool $includePayer, int $amount): array
    {
        return self::getContainer()->get('test.create_debt')->handle(new CreateDebtCommand('Dinner', null, $amount, $payer, $participants, $includePayer, Uuid::v4()->toRfc4122()), $payer)['body'];
    }
}
