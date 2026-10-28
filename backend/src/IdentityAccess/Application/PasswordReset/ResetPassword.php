<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\PasswordReset;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\PasswordChangeRepositoryPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\PasswordResetRateLimitPort;
use App\IdentityAccess\Application\Port\PasswordResetRepositoryPort;
use App\IdentityAccess\Application\Port\StoredPasswordResetToken;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UserSessionRepositoryPort;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\IdentityAccess\Domain\User\PasswordPolicy;
use App\IdentityAccess\Domain\User\PasswordPolicyViolation;
use DateTimeImmutable;

final readonly class ResetPassword
{
    public function __construct(
        private PasswordResetRepositoryPort $repository,
        private PasswordChangeRepositoryPort $identities,
        private UserSessionRepositoryPort $sessions,
        private ActionTokenCodecPort $tokenCodec,
        private PasswordPolicy $policy,
        private PasswordHashingPort $hasher,
        private PasswordResetRateLimitPort $limits,
        private TransactionPort $transaction,
        private ClockPort $clock,
    ) {
    }

    public function handle(ResetPasswordCommand $command): void
    {
        $digest = $this->tokenCodec->digest($command->token);
        $retryAfter = $this->limits->passwordResetRetryAfter($command->ipAddress, $digest ?? 'invalid:'.hash('sha256', $command->token));
        if (null !== $retryAfter) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
        }
        try {
            $this->policy->assertSatisfied($command->newPassword);
        } catch (PasswordPolicyViolation) {
            throw new ApplicationFailure(ApplicationFailureCode::PasswordPolicyViolation);
        }
        if (null === $digest) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
        }

        $this->transaction->run(function () use ($digest, $command): void {
            $userId = $this->repository->userIdForTokenHash($digest);
            $account = null === $userId ? null : $this->identities->accountForUpdate($userId);
            if (null === $userId || null === $account) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
            }
            $token = $this->repository->tokenForUpdate($digest);
            $now = $this->clock->now();
            $this->assertUsable($token, $userId, $now);
            if (null === $token) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
            }
            if ($account->verifyPassword($this->hasher, $command->newPassword)) {
                throw new ApplicationFailure(ApplicationFailureCode::PasswordUnchanged);
            }
            $this->repository->completeReset($token->id, $userId, $this->hasher->hash($command->newPassword), $now);
            $this->sessions->revokeAllUnfinishedForUser($userId, $now);
        });
    }

    private function assertUsable(?StoredPasswordResetToken $token, string $userId, DateTimeImmutable $now): void
    {
        $failure = match (true) {
            null === $token, $token->userId !== $userId, UserActionTokenPurpose::ResetPassword !== $token->purpose => ApplicationFailureCode::InvalidToken,
            null !== $token->invalidatedAt => ApplicationFailureCode::TokenInvalidated,
            null !== $token->usedAt => ApplicationFailureCode::TokenUsed,
            $token->expiresAt <= $now => ApplicationFailureCode::TokenExpired,
            default => null,
        };
        if (null !== $failure) {
            throw new ApplicationFailure($failure);
        }
    }
}
