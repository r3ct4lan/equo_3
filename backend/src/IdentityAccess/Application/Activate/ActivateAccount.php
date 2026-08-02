<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Activate;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Authorization\ActivationAccessDenial;
use App\IdentityAccess\Application\Authorization\ActivationAccessPolicy;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\IdentityAccess\Application\Port\TransactionPort;

final readonly class ActivateAccount
{
    public function __construct(
        private ActionTokenCodecPort $tokenCodec,
        private IdentityRepositoryPort $identityRepository,
        private ActivationAccessPolicy $accessPolicy,
        private RateLimitPort $rateLimit,
        private TransactionPort $transaction,
        private ClockPort $clock,
    ) {
    }

    public function handle(ActivateCommand $command): void
    {
        $tokenHash = $this->tokenCodec->digest($command->token);
        $fingerprint = $tokenHash ?? 'invalid:'.hash('sha256', $command->token);
        $retryAfter = $this->rateLimit->activationRetryAfter($command->ipAddress, $fingerprint);

        if (null !== $retryAfter) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
        }

        $now = $this->clock->now();
        $this->transaction->run(function () use ($tokenHash, $now): void {
            $stored = null === $tokenHash ? null : $this->identityRepository->activationTokenForUpdate($tokenHash);
            $decision = $this->accessPolicy->decide($stored?->access, $now);

            if (!$decision->isAllowed() || true === $stored?->userActive) {
                $this->throwDenied($decision->denial);
            }

            if (null === $stored) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
            }

            $this->identityRepository->activate($stored->tokenId, $decision->userId(), $now);
        });
    }

    private function throwDenied(?ActivationAccessDenial $denial): never
    {
        throw new ApplicationFailure(match ($denial) {
            ActivationAccessDenial::ExpiredToken => ApplicationFailureCode::TokenExpired, ActivationAccessDenial::UsedToken => ApplicationFailureCode::TokenUsed, ActivationAccessDenial::InvalidatedToken => ApplicationFailureCode::TokenInvalidated, default => ApplicationFailureCode::InvalidToken,
        });
    }
}
