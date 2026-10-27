<?php

declare(strict_types=1);

namespace App\Tests\Integration\IdentityAccess;

use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrinePasswordChangeRepository;
use App\IdentityAccess\Adapter\Persistence\Doctrine\DoctrineTransaction;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\PasswordChange\ChangePassword;
use App\IdentityAccess\Application\PasswordChange\ChangePasswordCommand;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PasswordChangePersistenceTest extends KernelTestCase
{
    private const string USER = '8c000000-0000-4000-8000-000000000001';
    private const string FOREIGN = '8c000000-0000-4000-8000-000000000002';
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
        foreach ([self::USER, self::FOREIGN] as $user) {
            $this->db->insert('app_user', ['id' => $user, 'name' => 'Security', 'email' => $user.'@example.test', 'password_hash' => $this->hasher()->hash('Correct password!'), 'is_active' => 1, 'created_at' => '2026-10-01 00:00:00+00']);
        }
        foreach ([1, 2, 3, 4, 5] as $number) {
            $this->db->insert('user_session', [
                'id' => '8c000000-0000-4000-8001-'.sprintf('%012d', $number),
                'user_id' => 5 === $number ? self::FOREIGN : self::USER,
                'refresh_token_hash' => 'sha256:'.hash('sha256', (string) $number),
                'created_at' => '2026-10-01 00:00:00+00',
                'expires_at' => 3 === $number ? '2026-10-02 00:00:00+00' : '2099-11-01 00:00:00+00',
                'revoked_at' => 4 === $number ? '2026-10-02 00:00:00+00' : null,
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testChangeRevokesOnlyUnfinishedOwnerSessionsAndPreservesIdentityAndTokens(): void
    {
        $before = $this->db->fetchAssociative('SELECT name, email, is_active, created_at FROM app_user WHERE id = ?', [self::USER]);
        $this->db->insert('user_action_token', ['id' => '8c000000-0000-4000-8002-000000000001', 'user_id' => self::USER, 'token_hash' => 'v1:'.hash('sha256', 'reset'), 'purpose' => 'RESET_PASSWORD', 'created_at' => '2026-10-01 00:00:00+00', 'expires_at' => '2099-11-01 00:00:00+00']);
        $tokens = $this->db->fetchAllAssociative('SELECT * FROM user_action_token');
        $this->change();
        self::assertSame($before, $this->db->fetchAssociative('SELECT name, email, is_active, created_at FROM app_user WHERE id = ?', [self::USER]));
        self::assertSame($tokens, $this->db->fetchAllAssociative('SELECT * FROM user_action_token'));
        $rows = $this->db->fetchAllAssociative('SELECT revoked_at FROM user_session ORDER BY id');
        self::assertNotNull($rows[0]['revoked_at']);
        self::assertSame($rows[0]['revoked_at'], $rows[1]['revoked_at']);
        self::assertNull($rows[2]['revoked_at']);
        self::assertSame('2026-10-02 00:00:00+00', $rows[3]['revoked_at']);
        self::assertNull($rows[4]['revoked_at']);
        self::assertTrue($this->hasher()->verify('  New password1 😀  ', $this->hash()));
        self::assertFalse($this->hasher()->verify('Correct password!', $this->hash()));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM email_delivery_outbox'));
    }

    public function testFailureAfterFlushRollsBackBothPasswordAndSessions(): void
    {
        $hash = $this->hash();
        $sessions = $this->db->fetchAllAssociative('SELECT * FROM user_session ORDER BY id');
        try {
            (new DoctrineTransaction($this->em))->run(function (): void {
                $this->change();
                $this->em->flush();
                throw new RuntimeException('Controlled change rollback.');
            });
        } catch (RuntimeException $failure) {
            self::assertSame('Controlled change rollback.', $failure->getMessage());
        }
        self::assertSame($hash, $this->hash());
        self::assertSame($sessions, $this->db->fetchAllAssociative('SELECT * FROM user_session ORDER BY id'));
    }

    public function testStaleHydratedCredentialAndActiveStateAreReloadedUnderLock(): void
    {
        $repository = new DoctrinePasswordChangeRepository($this->em);
        self::assertNotNull($repository->accountForUpdate(self::USER));
        $hash = $this->hasher()->hash('Already changed!');
        $this->db->executeStatement('UPDATE app_user SET password_hash = ? WHERE id = ?', [$hash, self::USER]);
        try {
            $this->change();
            self::fail('Old password must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::InvalidCredentials, $failure->failureCode);
        }
        self::assertSame($hash, $this->hash());
        $this->db->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [self::USER]);
        try {
            self::getContainer()->get(ChangePassword::class)->handle(new ChangePasswordCommand(self::USER, 'Already changed!', 'New password1!'));
            self::fail('Inactive user must fail.');
        } catch (ApplicationFailure $failure) {
            self::assertSame(ApplicationFailureCode::AuthenticationRequired, $failure->failureCode);
        }
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_session WHERE revoked_at IS NOT NULL'));
    }

    public function testSamePasswordAndNoSessionsAreAllowed(): void
    {
        $password = 'Correct password1!';
        $this->db->executeStatement('UPDATE app_user SET password_hash = ? WHERE id = ?', [$this->hasher()->hash($password), self::USER]);
        $this->db->executeStatement('DELETE FROM user_session WHERE user_id = ?', [self::USER]);
        self::getContainer()->get(ChangePassword::class)->handle(new ChangePasswordCommand(self::USER, $password, $password));
        self::assertTrue($this->hasher()->verify($password, $this->hash()));
    }

    private function change(): void
    {
        self::getContainer()->get(ChangePassword::class)->handle(new ChangePasswordCommand(self::USER, 'Correct password!', '  New password1 😀  '));
    }

    private function hash(): string
    {
        $hash = $this->db->fetchOne('SELECT password_hash FROM app_user WHERE id = ?', [self::USER]);
        self::assertIsString($hash);

        return $hash;
    }

    private function hasher(): PasswordHashingPort
    {
        return self::getContainer()->get(PasswordHashingPort::class);
    }
}
