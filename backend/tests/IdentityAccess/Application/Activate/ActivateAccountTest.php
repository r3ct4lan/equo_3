<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Application\Activate;

use App\IdentityAccess\Application\Activate\ActivateAccount;
use App\IdentityAccess\Application\Activate\ActivateCommand;
use App\IdentityAccess\Application\Authorization\ActivationAccessPolicy;
use App\IdentityAccess\Application\Authorization\ActivationTokenAccess;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\IdentityAccess\Application\Port\StoredActivationToken;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ActivateAccountTest extends TestCase
{
    public function testAuthorizedUserComesOnlyFromStoredToken(): void
    {
        $now = new DateTimeImmutable('2026-08-02T10:00:00Z');
        $codec = $this->createMock(ActionTokenCodecPort::class);
        $codec->expects(self::once())->method('digest')->with('v1.public')->willReturn('v1:digest');
        $repository = $this->createMock(IdentityRepositoryPort::class);
        $repository->expects(self::once())->method('activationTokenForUpdate')->with('v1:digest')->willReturn(new StoredActivationToken(
            '00000000-0000-4000-8000-000000000002',
            new ActivationTokenAccess(
                '00000000-0000-4000-8000-000000000001',
                UserActionTokenPurpose::ActivateAccount,
                $now->modify('+1 hour'),
            ),
            false,
        ));
        $repository->expects(self::once())->method('activate')->with(
            '00000000-0000-4000-8000-000000000002',
            '00000000-0000-4000-8000-000000000001',
            $now,
        );
        $rateLimit = $this->createStub(RateLimitPort::class);
        $rateLimit->method('activationRetryAfter')->willReturn(null);
        $transaction = $this->createStub(TransactionPort::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $clock = $this->createStub(ClockPort::class);
        $clock->method('now')->willReturn($now);

        (new ActivateAccount(
            $codec,
            $repository,
            new ActivationAccessPolicy(),
            $rateLimit,
            $transaction,
            $clock,
        ))->handle(new ActivateCommand('v1.public', '192.0.2.1'));
    }
}
