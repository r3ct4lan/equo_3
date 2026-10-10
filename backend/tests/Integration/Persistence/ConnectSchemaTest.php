<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Connects\Adapter\Persistence\Doctrine\Record\ConnectRecord;
use App\Invitations\Adapter\Persistence\Doctrine\Record\ConnectInvitationRecord;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConnectSchemaTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        foreach (['00000000-0000-4000-8000-000000000071', '00000000-0000-4000-8000-000000000072'] as $id) {
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Schema user', 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => '2026-10-10 12:00:00+00']);
        }
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        parent::tearDown();
    }

    public function testMappingRoundTripAndExpiredInvitationRetention(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $now = new DateTimeImmutable('2026-10-10T12:00:00Z');
        $connect = new ConnectRecord('00000000-0000-4000-8000-000000000073', '00000000-0000-4000-8000-000000000071', '00000000-0000-4000-8000-000000000072', $now);
        $em->persist($connect);
        foreach (['00000000-0000-4000-8000-000000000074', '00000000-0000-4000-8000-000000000075'] as $id) {
            $em->persist(new ConnectInvitationRecord($id, '00000000-0000-4000-8000-000000000071', hash('sha256', $id), $now->modify('-14 days'), $now->modify('-7 days')));
        }
        $em->flush();
        $em->clear();
        $stored = $em->find(ConnectRecord::class, '00000000-0000-4000-8000-000000000073');
        self::assertInstanceOf(ConnectRecord::class, $stored);
        self::assertSame('00000000-0000-4000-8000-000000000071', $stored->toDomain()->pair->first);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect_invitation WHERE created_by_id = ?', ['00000000-0000-4000-8000-000000000071']));
    }

    #[DataProvider('invalidPairs')]
    public function testDatabaseRejectsNonCanonicalOrMissingUserPairs(string $first, string $second): void
    {
        $this->expectException(DriverException::class);
        $this->db->insert('connect', ['id' => '00000000-0000-4000-8000-000000000076', 'first_user_id' => $first, 'second_user_id' => $second, 'created_at' => '2026-10-10 12:00:00+00']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPairs(): iterable
    {
        $a = '00000000-0000-4000-8000-000000000071';
        $b = '00000000-0000-4000-8000-000000000072';
        yield 'self' => [$a, $a];
        yield 'reverse' => [$b, $a];
        yield 'missing' => [$a, '00000000-0000-4000-8000-000000000099'];
    }

    public function testDatabaseRejectsDuplicatePair(): void
    {
        $row = ['first_user_id' => '00000000-0000-4000-8000-000000000071', 'second_user_id' => '00000000-0000-4000-8000-000000000072', 'created_at' => '2026-10-10 12:00:00+00'];
        $this->db->insert('connect', ['id' => '00000000-0000-4000-8000-000000000077'] + $row);
        $this->expectException(DriverException::class);
        $this->db->insert('connect', ['id' => '00000000-0000-4000-8000-000000000078'] + $row);
    }

    #[DataProvider('invalidInvitations')]
    public function testDatabaseRejectsInvalidInvitationLifecycle(?string $used, ?string $acceptor, string $expiry): void
    {
        $this->expectException(DriverException::class);
        $this->db->insert('connect_invitation', ['id' => '00000000-0000-4000-8000-000000000079', 'created_by_id' => '00000000-0000-4000-8000-000000000071', 'token_hash' => 'schema-only', 'created_at' => '2026-10-10 12:00:00+00', 'expires_at' => $expiry, 'used_at' => $used, 'accepted_by_id' => $acceptor]);
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function invalidInvitations(): iterable
    {
        $later = '2026-10-17 12:00:00+00';
        $used = '2026-10-10 13:00:00+00';
        yield 'used without acceptor' => [$used, null, $later];
        yield 'acceptor without used' => [null, '00000000-0000-4000-8000-000000000072', $later];
        yield 'self' => [$used, '00000000-0000-4000-8000-000000000071', $later];
        yield 'invalid expiry' => [null, null, '2026-10-10 12:00:00+00'];
    }
}
