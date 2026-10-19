<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Login;

use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Domain\Access\UserSession;
use DateTimeImmutable;

final readonly class IssueUserSession
{
    public function __construct(
        private UserSessionRepositoryPort $sessionRepository,
        private AccessTokenIssuerPort $accessTokenIssuer,
        private RefreshTokenCodecPort $refreshTokenCodec,
        private CsrfTokenCodecPort $csrfTokenCodec,
        private UuidPort $uuid,
    ) {
    }

    /** Call inside the transaction that holds the user lock. */
    public function issue(LoginUserView $user, DateTimeImmutable $now): LoginResult
    {
        $sessionId = $this->uuid->generate();
        $issuedRefreshToken = $this->refreshTokenCodec->issueForSession($sessionId);
        $session = UserSession::create($sessionId, $user->id, $issuedRefreshToken->tokenHash, $now);
        $this->sessionRepository->add($session);
        $issuedAccessToken = $this->accessTokenIssuer->issue($user->id);
        $csrfToken = $this->csrfTokenCodec->issue($sessionId);

        return new LoginResult(
            $issuedAccessToken->accessToken,
            $issuedAccessToken->expiresIn,
            $user,
            $issuedRefreshToken->publicToken,
            $csrfToken,
            $session->expiresAt,
        );
    }
}
