<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Refresh;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;

final readonly class RefreshSession
{
    public function __construct(
        private UserSessionRepositoryPort $sessionRepository,
        private IdentityRepositoryPort $identityRepository,
        private RefreshTokenCodecPort $refreshTokenCodec,
        private CsrfTokenCodecPort $csrfTokenCodec,
        private AccessTokenIssuerPort $accessTokenIssuer,
        private TransactionPort $transaction,
        private ClockPort $clock,
    ) {
    }

    public function handle(RefreshCommand $command): RefreshResult
    {
        $refreshTokenHash = $this->refreshTokenCodec->digest($command->refreshToken);

        if (null === $refreshTokenHash) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRefreshToken);
        }

        return $this->transaction->run(function () use ($command, $refreshTokenHash): RefreshResult {
            $session = $this->sessionRepository->findByRefreshTokenHashForUpdate($refreshTokenHash);

            if (null === $session) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidRefreshToken);
            }

            if (!$this->csrfTokenCodec->verify($session->id, $command->csrfToken)) {
                throw new ApplicationFailure(ApplicationFailureCode::Forbidden);
            }

            $now = $this->clock->now();
            if (!$session->isActive($now)) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidRefreshToken);
            }

            $owner = $this->identityRepository->currentUserState($session->userId);
            if (null === $owner || $owner->id !== $session->userId) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidRefreshToken);
            }

            if (!$owner->isActive) {
                throw new ApplicationFailure(ApplicationFailureCode::AccountInactive);
            }

            $issuedRefreshToken = $this->refreshTokenCodec->issue();
            $session->rotate($issuedRefreshToken->tokenHash, $now);
            $this->sessionRepository->save($session);
            $issuedAccessToken = $this->accessTokenIssuer->issue($session->userId);
            $csrfToken = $this->csrfTokenCodec->issue($session->id);

            return new RefreshResult(
                $issuedAccessToken->accessToken,
                $issuedAccessToken->expiresIn,
                $issuedRefreshToken->publicToken,
                $csrfToken,
                $session->expiresAt,
            );
        });
    }
}
