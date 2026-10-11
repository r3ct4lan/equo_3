<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Debts\Adapter\Persistence\Doctrine\Record\DebtParticipantRecord;
use App\Debts\Adapter\Persistence\Doctrine\Record\DebtRecord;
use App\Transfers\Adapter\Persistence\Doctrine\Record\TransferRecord;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FinancialSchemaTest extends KernelTestCase
{
    private Connection $db;
    private const string A = '00000000-0000-4000-8000-000000000081';
    private const string B = '00000000-0000-4000-8000-000000000082';
    private const string D = '00000000-0000-4000-8000-000000000083';
    private const string P = '00000000-0000-4000-8000-000000000084';
    private const string C = '00000000-0000-4000-8000-000000000085';
    private const string T = '00000000-0000-4000-8000-000000000086';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        foreach ([self::A, self::B] as $id) {
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Financial schema', 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => true, 'created_at' => '2026-10-11 12:00:00+00'], ['is_active' => 'boolean']);
        }
        $this->db->insert('connect', ['id' => self::C, 'first_user_id' => self::A, 'second_user_id' => self::B, 'created_at' => '2026-10-11 12:00:00+00']);
        $this->db->insert('debt', $this->debtRow());
        $this->db->insert('debt_participant', ['id' => self::P, 'debt_id' => self::D, 'user_id' => self::B, 'created_at' => '2026-10-11 12:00:00+00']);
        $this->db->insert('transfer', $this->transferRow());
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function debtRow(): array
    {
        return ['id' => self::D, 'title' => 'Dinner', 'description' => null, 'total_amount' => 1001, 'payer_id' => self::A, 'created_by_id' => self::A, 'created_at' => '2026-10-11 12:00:00+00'];
    }

    /** @return array<string,mixed> */
    private function transferRow(): array
    {
        return ['id' => self::T, 'connect_id' => self::C, 'sender_id' => self::A, 'receiver_id' => self::B, 'amount' => 1001, 'type' => 'DEBT_SHARE', 'created_by_id' => null, 'created_at' => '2026-10-11 12:00:00+00', 'debt_id' => self::D, 'debt_participant_id' => self::P, 'related_transfer_id' => null];
    }

    public function testRecordsRoundTripWithoutCrossModuleAssociations(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $p = $em->find(DebtParticipantRecord::class, self::P);
        self::assertInstanceOf(DebtParticipantRecord::class, $p);
        $d = $em->find(DebtRecord::class, self::D);
        self::assertInstanceOf(DebtRecord::class, $d);
        $t = $em->find(TransferRecord::class, self::T);
        self::assertInstanceOf(TransferRecord::class, $t);
        $debt = $d->toDomain([$p->toDomain()]);
        self::assertSame(1001, $debt->shareAmount());
        self::assertFalse($debt->includePayerInSplit());
        self::assertSame(1, $debt->version);
        self::assertSame(self::P, $t->toDomain()->debtParticipantId);
        foreach ([DebtRecord::class, DebtParticipantRecord::class, TransferRecord::class] as $class) {
            self::assertSame([], $em->getClassMetadata($class)->getAssociationNames());
        }
        $now = new DateTimeImmutable('2026-10-11T12:00:00Z');
        $em->persist(new TransferRecord('00000000-0000-4000-8000-000000000088', self::C, self::B, self::A, 200, 'MANUAL', self::B, $now, null, null, self::T, true));
        $em->flush();
        $em->clear();
        $manual = $em->find(TransferRecord::class, '00000000-0000-4000-8000-000000000088');
        self::assertInstanceOf(TransferRecord::class, $manual);
        self::assertTrue($manual->toDomain()->isDeleted);
        self::assertSame(self::T, $manual->toDomain()->relatedTransferId);
    }

    #[DataProvider('invalidUpdates')]
    public function testDatabaseRejectsInvalidFinancialRows(string $table, string $column, mixed $value): void
    {
        $this->expectException(DriverException::class);
        $this->db->update($table, [$column => $value], ['id' => 'debt' === $table ? self::D : self::T]);
    }

    /** @return iterable<string,array{string,string,mixed}> */
    public static function invalidUpdates(): iterable
    {
        yield 'zero total' => ['debt', 'total_amount', 0];
        yield 'negative total' => ['debt', 'total_amount', -1];
        yield 'empty title' => ['debt', 'title', '  '];
        yield 'zero debt version' => ['debt', 'version', 0];
        yield 'unknown payer FK' => ['debt', 'payer_id', '00000000-0000-4000-8000-000000000099'];
        yield 'zero share' => ['transfer', 'amount', 0];
        yield 'self-transfer' => ['transfer', 'receiver_id', self::A];
        yield 'zero transfer version' => ['transfer', 'version', 0];
        yield 'unknown type' => ['transfer', 'type', 'WRONG'];
        yield 'automatic author' => ['transfer', 'created_by_id', self::A];
        yield 'automatic missing debt' => ['transfer', 'debt_id', null];
        yield 'automatic missing participant' => ['transfer', 'debt_participant_id', null];
        yield 'automatic relation' => ['transfer', 'related_transfer_id', self::T];
        yield 'manual with debt links' => ['transfer', 'type', 'MANUAL'];
        yield 'unknown connection FK' => ['transfer', 'connect_id', '00000000-0000-4000-8000-000000000099'];
        yield 'unknown receiver FK' => ['transfer', 'receiver_id', '00000000-0000-4000-8000-000000000099'];
    }

    public function testDuplicateParticipantIncludingDeletedIsRejected(): void
    {
        $this->db->executeStatement('UPDATE debt_participant SET is_deleted=true WHERE id=?', [self::P]);
        $this->expectException(DriverException::class);
        $this->db->insert('debt_participant', ['id' => '00000000-0000-4000-8000-000000000089', 'debt_id' => self::D, 'user_id' => self::B, 'created_at' => '2026-10-11 12:00:00+00']);
    }

    public function testDuplicateAutomaticShareIncludingDeletedIsRejected(): void
    {
        $this->db->executeStatement('UPDATE transfer SET is_deleted=true WHERE id=?', [self::T]);
        $this->expectException(DriverException::class);
        $this->db->insert('transfer', array_replace($this->transferRow(), ['id' => '00000000-0000-4000-8000-000000000089']));
    }

    #[DataProvider('protectedRows')]
    public function testForeignKeysPreserveFinancialHistory(string $table, string $id): void
    {
        $this->expectException(DriverException::class);
        $this->db->delete($table, ['id' => $id]);
    }

    /** @return iterable<array{string,string}> */
    public static function protectedRows(): iterable
    {
        yield ['app_user', self::A];
        yield ['app_user', self::B];
        yield ['connect', self::C];
        yield ['debt', self::D];
        yield ['debt_participant', self::P];
    }
}
