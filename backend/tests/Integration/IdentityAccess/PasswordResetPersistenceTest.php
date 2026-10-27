<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineIdentityRepository;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrinePasswordResetRepository;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineTransaction;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineUserSessionRepository;
use App\IdentityAccess\Adapter\System\SymfonyUuid;
use App\IdentityAccess\Application\PasswordReset\PasswordResetRequestCommand;
use App\IdentityAccess\Application\PasswordReset\RequestPasswordReset;
use App\IdentityAccess\Application\PasswordReset\ResetPassword;
use App\IdentityAccess\Application\PasswordReset\ResetPasswordCommand;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\PasswordResetOutboxPort;
use App\IdentityAccess\Application\Port\PasswordResetRateLimitPort;
use App\IdentityAccess\Application\Port\PayloadCipherPort;
use App\IdentityAccess\Domain\User\PasswordPolicy;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\DoctrineEmailOutbox;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PasswordResetPersistenceTest extends KernelTestCase
{
    private const string USER = '89000000-0000-4000-8000-000000000001';
    private const string FOREIGN = '89000000-0000-4000-8000-000000000002';
    private EntityManagerInterface $em;
    private Connection $db;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
        $this->now = new DateTimeImmutable('2026-10-09T12:00:00Z');
        foreach ([self::USER, self::FOREIGN] as $user) {
            $this->db->insert('app_user', ['id' => $user, 'name' => 'Recovery', 'email' => $user.'@example.test', 'password_hash' => 'original-hash', 'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00']);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testReissueInvalidatesExpiredResetOnlyAndKeepsRawCapabilityEncrypted(): void
    {
        $this->token(1, 'RESET_PASSWORD', '2026-10-09 11:00:00+00');
        $this->token(2, 'ACTIVATE_ACCOUNT');
        $this->token(3, 'CHANGE_EMAIL');
        $this->request()->handle(new PasswordResetRequestCommand(self::USER.'@example.test', '192.0.2.1'));
        $first = $this->capability();
        $this->request()->handle(new PasswordResetRequestCommand(self::USER.'@example.test', '192.0.2.1'));
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM user_action_token WHERE purpose = 'RESET_PASSWORD' AND invalidated_at IS NOT NULL"));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM user_action_token WHERE purpose = 'RESET_PASSWORD' AND used_at IS NULL AND invalidated_at IS NULL"));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM user_action_token WHERE purpose <> 'RESET_PASSWORD' AND invalidated_at IS NOT NULL"));
        $rows = $this->db->fetchAllAssociative('SELECT template_key, encrypted_payload FROM email_delivery_outbox');
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('reset-password', $row['template_key']);
            $ciphertext = is_resource($row['encrypted_payload']) ? stream_get_contents($row['encrypted_payload']) : $row['encrypted_payload'];
            self::assertIsString($ciphertext);
            self::assertStringNotContainsString($first, $ciphertext);
        }
        self::assertSame(1800, (int) $this->db->fetchOne("SELECT EXTRACT(EPOCH FROM expires_at - created_at) FROM user_action_token WHERE purpose = 'RESET_PASSWORD' AND invalidated_at IS NULL"));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM user_action_token WHERE purpose = 'RESET_PASSWORD' AND payload IS NOT NULL"));
    }

    #[DataProvider('activeProvider')]
    public function testApplyAtomicallyChangesPasswordConsumesTokenAndRevokesOnlyUnfinishedOwnedSessions(bool $active): void
    {
        $this->db->executeStatement('UPDATE app_user SET is_active = ? WHERE id = ?', [$active ? 1 : 0, self::USER]);
        $this->request()->handle(new PasswordResetRequestCommand(self::USER.'@example.test', '192.0.2.1'));
        foreach ([[1, self::USER, '2026-11-01 00:00:00+00', null], [2, self::USER, '2026-10-09 12:00:00+00', null], [3, self::USER, '2026-11-01 00:00:00+00', '2026-10-02 00:00:00+00'], [4, self::FOREIGN, '2026-11-01 00:00:00+00', null]] as [$number, $owner, $expiry, $revoked]) {
            $this->db->insert('user_session', ['id' => '89000000-0000-4000-8001-'.sprintf('%012d', $number), 'user_id' => $owner, 'refresh_token_hash' => 'sha256:'.hash('sha256', (string) $number), 'created_at' => '2026-10-01 00:00:00+00', 'expires_at' => $expiry, 'revoked_at' => $revoked]);
        }
        $password = '  Exact new password1 😀  ';
        $this->reset()->handle(new ResetPasswordCommand($this->capability(), $password, '192.0.2.1'));
        $user = $this->db->fetchAssociative('SELECT password_hash, is_active FROM app_user WHERE id = ?', [self::USER]);
        self::assertIsArray($user);
        self::assertSame($active, $user['is_active']);
        self::assertTrue(self::getContainer()->get(PasswordHashingPort::class)->verify($password, $user['password_hash']));
        self::assertFalse(self::getContainer()->get(PasswordHashingPort::class)->verify(trim($password), $user['password_hash']));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE used_at IS NOT NULL AND invalidated_at IS NULL'));
        $rows = $this->db->fetchAllAssociative('SELECT revoked_at FROM user_session ORDER BY id');
        self::assertNotNull($rows[0]['revoked_at']);
        self::assertNull($rows[1]['revoked_at']);
        self::assertSame('2026-10-02 00:00:00+00', $rows[2]['revoked_at']);
        self::assertNull($rows[3]['revoked_at']);
    }

    /** @return iterable<string, array{bool}> */
    public static function activeProvider(): iterable
    {
        yield 'active' => [true];
        yield 'inactive remains inactive' => [false];
    }

    public function testFailureAfterFlushRollsBackPasswordTokenAndSession(): void
    {
        $this->request()->handle(new PasswordResetRequestCommand(self::USER.'@example.test', '192.0.2.1'));
        $capability = $this->capability();
        $this->db->insert('user_session', ['id' => '89000000-0000-4000-8001-000000000001', 'user_id' => self::USER, 'refresh_token_hash' => 'sha256:'.hash('sha256', 'rollback'), 'created_at' => '2026-10-01 00:00:00+00', 'expires_at' => '2026-11-01 00:00:00+00']);
        try {
            (new DoctrineTransaction($this->em))->run(function () use ($capability): void {
                $this->reset()->handle(new ResetPasswordCommand($capability, 'New password1!', '192.0.2.1'));
                $this->em->flush();
                throw new RuntimeException('Controlled reset rollback.');
            });
        } catch (RuntimeException $failure) {
            self::assertSame('Controlled reset rollback.', $failure->getMessage());
        }
        self::assertSame('original-hash', $this->db->fetchOne('SELECT password_hash FROM app_user WHERE id = ?', [self::USER]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE used_at IS NOT NULL'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE revoked_at IS NOT NULL'));
    }

    public function testOutboxFailureRollsBackReplacementAndInvalidation(): void
    {
        $this->token(1, 'RESET_PASSWORD');
        $outbox = $this->createStub(PasswordResetOutboxPort::class);
        $outbox->method('enqueuePasswordReset')->willThrowException(new RuntimeException('Controlled outbox failure.'));
        try {
            $this->request($outbox)->handle(new PasswordResetRequestCommand(self::USER.'@example.test', '192.0.2.1'));
        } catch (RuntimeException $failure) {
            self::assertSame('Controlled outbox failure.', $failure->getMessage());
        }
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE invalidated_at IS NOT NULL'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM email_delivery_outbox'));
    }

    public function testHydratedIdentityIsRefreshedAfterPasswordChange(): void
    {
        $repository = new DoctrineIdentityRepository($this->em);
        $before = $repository->loginIdentityByEmail(self::USER.'@example.test');
        self::assertNotNull($before);
        $hasher = $this->createStub(PasswordHashingPort::class);
        $hasher->method('verify')->willReturnCallback(static fn (string $plain, string $hash): bool => $plain === $hash);
        self::assertTrue($repository->accountForUpdate(self::USER.'@example.test')?->verifyPassword($hasher, 'original-hash'));
        $this->db->executeStatement('UPDATE app_user SET password_hash = ? WHERE id = ?', ['replacement-hash', self::USER]);
        $after = $repository->loginIdentityByEmail(self::USER.'@example.test');
        self::assertNotNull($after);
        self::assertFalse($before->hasSameCredentialAs($after));
        $this->db->executeStatement('UPDATE app_user SET password_hash = ? WHERE id = ?', ['latest-hash', self::USER]);
        self::assertTrue($repository->accountForUpdate(self::USER.'@example.test')?->verifyPassword($hasher, 'latest-hash'));
    }

    private function request(?PasswordResetOutboxPort $outbox = null): RequestPasswordReset
    {
        return new RequestPasswordReset(new DoctrinePasswordResetRepository($this->em), self::getContainer()->get(ActionTokenCodecPort::class), self::getContainer()->get(PayloadCipherPort::class), $outbox ?? new DoctrineEmailOutbox($this->em), $this->limits(), new DoctrineTransaction($this->em), $this->clock(), new SymfonyUuid());
    }

    private function reset(): ResetPassword
    {
        return new ResetPassword(new DoctrinePasswordResetRepository($this->em), new DoctrineIdentityRepository($this->em), new DoctrineUserSessionRepository($this->em), self::getContainer()->get(ActionTokenCodecPort::class), new PasswordPolicy(), self::getContainer()->get(PasswordHashingPort::class), $this->limits(), new DoctrineTransaction($this->em), $this->clock());
    }

    private function limits(): PasswordResetRateLimitPort
    {
        $limits = $this->createStub(PasswordResetRateLimitPort::class);
        $limits->method('passwordResetRequestRetryAfter')->willReturn(null);
        $limits->method('passwordResetRetryAfter')->willReturn(null);

        return $limits;
    }

    private function clock(): ClockPort
    {
        $clock = $this->createStub(ClockPort::class);
        $clock->method('now')->willReturn($this->now);

        return $clock;
    }

    private function capability(): string
    {
        $encrypted = $this->db->fetchOne("SELECT encrypted_payload FROM email_delivery_outbox WHERE user_action_token_id IN (SELECT id FROM user_action_token WHERE invalidated_at IS NULL AND purpose = 'RESET_PASSWORD')");
        $encrypted = is_resource($encrypted) ? stream_get_contents($encrypted) : $encrypted;
        self::assertIsString($encrypted);
        $payload = self::getContainer()->get(PayloadCipherPort::class)->decrypt($encrypted);

        return $payload['token'];
    }

    private function token(int $number, string $purpose, string $expiry = '2026-11-01 00:00:00+00'): void
    {
        $this->db->insert('user_action_token', ['id' => '89000000-0000-4000-8002-'.sprintf('%012d', $number), 'user_id' => self::USER, 'token_hash' => 'v1:'.hash('sha256', $purpose), 'purpose' => $purpose, 'created_at' => '2026-10-01 00:00:00+00', 'expires_at' => $expiry]);
    }
}
