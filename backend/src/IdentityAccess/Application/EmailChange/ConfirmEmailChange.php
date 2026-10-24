<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\EmailChange;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\EmailChangeRateLimitPort;
use App\IdentityAccess\Application\Port\EmailChangeRepositoryPort;
use App\IdentityAccess\Application\Port\StoredEmailChangeToken;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\IdentityAccess\Domain\User\EmailAddress;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ConfirmEmailChange
{
    public function __construct(
        private EmailChangeRepositoryPort $repository,
        private ActionTokenCodecPort $tokenCodec,
        private EmailChangeRateLimitPort $limits,
        private TransactionPort $transaction,
        private ClockPort $clock,
    ) {
    }

    public function handle(EmailChangeConfirmationCommand $command): void
    {
        $digest = $this->tokenCodec->digest($command->token);
        $retryAfter = $this->limits->emailChangeConfirmationRetryAfter($command->ipAddress, $digest ?? 'invalid:'.hash('sha256', $command->token));
        if (null !== $retryAfter) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
        }
        if (null === $digest) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
        }

        $this->transaction->run(function () use ($digest): void {
            $userId = $this->repository->userIdForTokenHash($digest);
            if (null === $userId || null === $this->repository->accountForUpdate($userId)) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
            }
            $token = $this->repository->tokenForUpdate($digest);
            $now = $this->clock->now();
            $this->assertUsable($token, $userId, $now);
            if (null === $token) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
            }
            $newEmailValue = $token->payload['newEmail'] ?? null;
            if (!is_string($newEmailValue)) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
            }
            try {
                $newEmail = new EmailAddress($newEmailValue);
            } catch (InvalidArgumentException) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
            }
            if ($this->repository->emailExists($newEmail->value)) {
                throw new ApplicationFailure(ApplicationFailureCode::EmailAlreadyInUse);
            }
            $this->repository->completeChange($token->id, $userId, $newEmail->value, $now);
        });
    }

    private function assertUsable(?StoredEmailChangeToken $token, string $userId, DateTimeImmutable $now): void
    {
        $failure = match (true) {
            null === $token, $token->userId !== $userId, UserActionTokenPurpose::ChangeEmail !== $token->purpose => ApplicationFailureCode::InvalidToken,
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
