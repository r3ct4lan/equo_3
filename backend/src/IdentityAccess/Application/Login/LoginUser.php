<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Login;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\AccessTokenIssuerPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CsrfTokenCodecPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\IdentityAccess\Application\Port\RefreshTokenCodecPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Domain\Access\UserSession;
use App\IdentityAccess\Domain\User\EmailAddress;

final readonly class LoginUser
{
    public function __construct(
        private IdentityRepositoryPort $identityRepository,
        private UserSessionRepositoryPort $sessionRepository,
        private PasswordHashingPort $passwordHasher,
        private AccessTokenIssuerPort $accessTokenIssuer,
        private RefreshTokenCodecPort $refreshTokenCodec,
        private CsrfTokenCodecPort $csrfTokenCodec,
        private RateLimitPort $rateLimit,
        private TransactionPort $transaction,
        private ClockPort $clock,
        private UuidPort $uuid,
    ) {
    }

    public function handle(LoginCommand $command): LoginResult
    {
        $email = new EmailAddress($command->email);
        $retryAfter = $this->rateLimit->loginRetryAfter($command->ipAddress, $email->value);

        if (null !== $retryAfter) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
        }

        $now = $this->clock->now();

        return $this->transaction->run(function () use ($command, $email, $now): LoginResult {
            $identity = $this->identityRepository->loginIdentityByEmail($email->value);
            $passwordValid = null === $identity
                ? $this->passwordHasher->verify($command->password, null)
                : $identity->verifyPassword($this->passwordHasher, $command->password);

            if (null === $identity || !$passwordValid) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidCredentials);
            }

            if (!$identity->isActive) {
                throw new ApplicationFailure(ApplicationFailureCode::AccountInactive);
            }

            $sessionId = $this->uuid->generate();
            $issuedRefreshToken = $this->refreshTokenCodec->issueForSession($sessionId);
            $session = UserSession::create(
                $sessionId,
                $identity->id,
                $issuedRefreshToken->tokenHash,
                $now,
            );
            $this->sessionRepository->add($session);
            $issuedAccessToken = $this->accessTokenIssuer->issue($identity->id);
            $csrfToken = $this->csrfTokenCodec->issue($sessionId);

            return new LoginResult(
                $issuedAccessToken->accessToken,
                $issuedAccessToken->expiresIn,
                new LoginUserView($identity->id, $identity->name, $identity->email, $identity->isActive),
                $issuedRefreshToken->publicToken,
                $csrfToken,
                $session->expiresAt,
            );
        });
    }
}
