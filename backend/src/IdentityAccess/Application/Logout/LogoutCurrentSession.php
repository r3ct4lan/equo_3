<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Logout;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;

final readonly class LogoutCurrentSession
{
    public function __construct(
        private UserSessionRepositoryPort $sessionRepository,
        private RefreshTokenCodecPort $refreshTokenCodec,
        private CsrfTokenCodecPort $csrfTokenCodec,
        private TransactionPort $transaction,
        private ClockPort $clock,
    ) {
    }

    public function handle(LogoutCurrentSessionCommand $command): void
    {
        $parsedRefreshToken = $this->refreshTokenCodec->parse($command->refreshToken);

        if (null === $parsedRefreshToken) {
            return;
        }

        $this->transaction->run(function () use ($command, $parsedRefreshToken): void {
            $session = $this->sessionRepository->findByIdForUpdate($parsedRefreshToken->sessionId);

            if (null === $session) {
                return;
            }

            if (!$this->csrfTokenCodec->verify($session->id, $command->csrfToken)) {
                throw new ApplicationFailure(ApplicationFailureCode::Forbidden);
            }

            $now = $this->clock->now();

            if (!$session->isActive($now)) {
                return;
            }

            $session->revoke($now);
            $this->sessionRepository->save($session);
        });
    }
}
