<?php

declare(strict_types=1);

namespace App\Tests\Integration\Invitations;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\Invitations\Application\InvitationService;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InvitationFlowTest extends KernelTestCase
{
    private Connection $db;
    private InvitationService $service;
    /** @var list<string> */ private array $users = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->db = $c->get(Connection::class);
        $this->db->beginTransaction();
        $c->get('test.rate_limiter_cache')->clear();
        for ($n = 0; $n < 4; ++$n) {
            $id = Uuid::v4()->toRfc4122();
            $this->users[] = $id;
            $this->db->insert('app_user', ['id' => $id, 'name' => 'Person '.$n, 'email' => $id.'@example.test', 'password_hash' => 'test-only', 'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00']);
        }
        $this->service = $c->get('test.invitation_service');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        parent::tearDown();
    }

    public function testSameLinkWithoutExtendingExpiryThenAcceptAndReplay(): void
    {
        [$a,$b,$c] = $this->users;
        $first = $this->service->create($a);
        $second = $this->service->create($a);
        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['token'], $second['token']);
        self::assertSame($first['expiresAt'], $second['expiresAt']);
        $row = $this->db->fetchAssociative('SELECT * FROM connect_invitation WHERE created_by_id = ?', [$a]);
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $first['token']), $row['token_hash']);
        self::assertNotContains($first['token'], $row);
        self::assertSame(604800, (new DateTimeImmutable((string) $row['expires_at']))->getTimestamp() - (new DateTimeImmutable((string) $row['created_at']))->getTimestamp());
        $preview = $this->service->preview($first['token']);
        self::assertSame(['id' => $a, 'name' => 'Person 0'], $preview['inviter']);
        $accepted = $this->service->accept($first['token'], $b, '192.0.2.81');
        self::assertTrue($accepted['created']);
        self::assertSame('created', $accepted['outcome']);
        self::assertSame($a, $accepted['view']['otherUser']['id']);
        self::assertArrayNotHasKey('email', $accepted['view']['otherUser']);
        $again = $this->service->accept($first['token'], $b, '192.0.2.81');
        self::assertFalse($again['created']);
        self::assertSame('confirmed', $again['outcome']);
        self::assertSame($accepted['view'], $again['view']);
        $new = $this->service->create($a);
        self::assertNotSame($first['token'], $new['token']);
        $existing = $this->service->accept($new['token'], $b, '192.0.2.81');
        self::assertFalse($existing['created']);
        self::assertSame('already_connected', $existing['outcome']);
        self::assertSame($accepted['view']['id'], $existing['view']['id']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect WHERE first_user_id IN (?,?) AND second_user_id IN (?,?)', [$a, $b, $a, $b]));
        $this->failure('TOKEN_USED', fn () => $this->service->accept($first['token'], $c, '192.0.2.81'));
    }

    public function testExpiredLinkGetsNewInvitationAndOldRowIsRetained(): void
    {
        $first = $this->service->create($this->users[0]);
        $this->db->executeStatement('UPDATE connect_invitation SET created_at = now() - interval \'8 days\', expires_at = now() - interval \'1 day\' WHERE created_by_id = ?', [$this->users[0]]);
        $second = $this->service->create($this->users[0]);
        self::assertNotSame($first['token'], $second['token']);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect_invitation WHERE created_by_id = ?', [$this->users[0]]));
        $this->failure('TOKEN_EXPIRED', fn () => $this->service->accept($first['token'], $this->users[1], '192.0.2.81'));
    }

    public function testOwnInvitationIsRejectedWithoutWrites(): void
    {
        $token = $this->service->create($this->users[0])['token'];
        $this->failure('VALIDATION_ERROR', fn () => $this->service->accept($token, $this->users[0], '192.0.2.81'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect_invitation WHERE created_by_id = ? AND used_at IS NOT NULL', [$this->users[0]]));
    }

    public function testInactiveAuthorPreviewAndAcceptance(): void
    {
        $token = $this->service->create($this->users[0])['token'];
        $this->db->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$this->users[0]]);
        self::assertFalse($this->service->preview($token)['canAccept']);
        $this->failure('INACTIVE_USER', fn () => $this->service->accept($token, $this->users[1], '192.0.2.81'));
    }

    public function testInactiveAcceptorIsFreshlyChecked(): void
    {
        $token = $this->service->create($this->users[0])['token'];
        $this->db->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$this->users[1]]);
        $this->failure('INACTIVE_USER', fn () => $this->service->accept($token, $this->users[1], '192.0.2.81'));
    }

    public function testValidSignatureButWrongStoredHashFailsEveryPublicOperation(): void
    {
        $token = $this->service->create($this->users[0])['token'];
        $this->db->executeStatement('UPDATE connect_invitation SET token_hash = ? WHERE created_by_id = ?', [str_repeat('0', 64), $this->users[0]]);
        $this->failure('RESOURCE_NOT_FOUND', fn () => $this->service->preview($token));
        $this->failure('RESOURCE_NOT_FOUND', fn () => $this->service->accept($token, $this->users[1], '192.0.2.81'));
    }

    public function testPreviewDoesNotDiscloseUsedInvitation(): void
    {
        $token = $this->service->create($this->users[0])['token'];
        $this->service->accept($token, $this->users[1], '192.0.2.81');
        $this->failure('TOKEN_USED', fn () => $this->service->preview($token));
    }

    public function testInvitationLimiterBlocksSixthRequestWithoutAcceptance(): void
    {
        $token = $this->service->create($this->users[0])['token'];
        for ($n = 0; $n < 5; ++$n) {
            $this->failure('VALIDATION_ERROR', fn () => $this->service->accept($token, $this->users[0], '192.0.2.81'));
        }
        $this->failure('RATE_LIMIT_EXCEEDED', fn () => $this->service->accept($token, $this->users[1], '192.0.2.82'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect_invitation WHERE created_by_id = ? AND used_at IS NOT NULL', [$this->users[0]]));
    }

    public function testAtomicRollbackAfterConnectCreation(): void
    {
        $token = $this->service->create($this->users[0])['token'];
        $this->db->executeStatement("CREATE FUNCTION pg_temp.reject_invitation_use() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END;'");
        $this->db->executeStatement('CREATE TRIGGER test_reject_use BEFORE UPDATE ON connect_invitation FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_invitation_use()');
        try {
            $this->service->accept($token, $this->users[1], '192.0.2.81');
            self::fail('Write must fail.');
        } catch (\Doctrine\DBAL\Exception) {
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect WHERE first_user_id IN (?,?) AND second_user_id IN (?,?)', [$this->users[0], $this->users[1], $this->users[0], $this->users[1]]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM connect_invitation WHERE created_by_id = ? AND used_at IS NOT NULL', [$this->users[0]]));
    }

    public function testConnectListPaginationPrivacyAndInactiveSideRemainVisible(): void
    {
        $a = $this->users[0];
        foreach (array_slice($this->users, 1) as $b) {
            $token = $this->service->create($a)['token'];
            $this->service->accept($token, $b, '192.0.2.81');
        }
        $connects = self::getContainer()->get('test.connect_service');
        $first = $connects->page($a, 2);
        self::assertCount(2, $first['items']);
        self::assertTrue($first['pagination']['hasMore']);
        $second = $connects->page($a, 2, $first['pagination']['nextCursor']);
        self::assertCount(1, $second['items']);
        self::assertFalse($second['pagination']['hasMore']);
        self::assertCount(3, array_unique(array_column([...$first['items'], ...$second['items']], 'id')));
        $id = $first['items'][0]['id'];
        self::assertIsString($id);
        $other = $first['items'][0]['otherUser']['id'];
        $this->db->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$other]);
        $detail = $connects->detail($id, $a);
        self::assertFalse($detail['canCreateDebt']);
        self::assertSame('OTHER_USER_INACTIVE', $detail['restriction']['code']);
        self::assertSame(0, $detail['balanceAmount']);
        $third = array_values(array_filter($this->users, fn (string $u): bool => $u !== $a && $u !== $other))[0];
        $this->failure('RESOURCE_NOT_FOUND', fn () => $connects->detail($id, $third));
        $this->failure('RESOURCE_NOT_FOUND', fn () => $connects->detail('invalid', $a));
        $this->failure('INVALID_REQUEST', fn () => $connects->page($a, 101));
        $this->failure('INVALID_REQUEST', fn () => $connects->page($a, 20, 'invalid'));
    }

    private function failure(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected safe failure.');
        } catch (ApplicationFailure $failure) {
            self::assertSame($code, $failure->failureCode->value);
            if ('RATE_LIMIT_EXCEEDED' === $code) {
                self::assertGreaterThan(0, $failure->retryAfter);
            }
        }
    }
}
