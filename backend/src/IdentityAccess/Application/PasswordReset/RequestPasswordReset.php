<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\PasswordReset;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\PasswordResetOutboxPort;
use App\IdentityAccess\Application\Port\PasswordResetRateLimitPort;
use App\IdentityAccess\Application\Port\PasswordResetRepositoryPort;
use App\IdentityAccess\Application\Port\PayloadCipherPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\IdentityAccess\Domain\User\EmailAddress;

final readonly class RequestPasswordReset
{
    public function __construct(
        private PasswordResetRepositoryPort $repository,
        private ActionTokenCodecPort $tokenCodec,
        private PayloadCipherPort $payloadCipher,
        private PasswordResetOutboxPort $outbox,
        private PasswordResetRateLimitPort $limits,
        private TransactionPort $transaction,
        private ClockPort $clock,
        private UuidPort $uuid,
    ) {
    }

    public function handle(PasswordResetRequestCommand $command): void
    {
        $email = new EmailAddress($command->email);
        $retryAfter = $this->limits->passwordResetRequestRetryAfter($command->ipAddress, $email->value);
        if (null !== $retryAfter) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
        }

        $this->transaction->run(function () use ($email): void {
            $account = $this->repository->accountForUpdate($email->value);
            if (null === $account) {
                return;
            }
            $now = $this->clock->now();
            $issued = $this->tokenCodec->issue();
            $token = new UserActionToken($this->uuid->generate(), $account->id, $issued->tokenHash, UserActionTokenPurpose::ResetPassword, $now, $now->modify('+30 minutes'));
            $this->repository->replaceToken($token, $now);
            $this->outbox->enqueuePasswordReset($this->uuid->generate(), $token->id, $account->email, $this->payloadCipher->encrypt(['token' => $issued->publicToken, 'locale' => $account->locale]), $now);
        });
    }
}
