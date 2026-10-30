<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\EmailChange;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\EmailChangeOutboxPort;
use App\IdentityAccess\Application\Port\EmailChangeRateLimitPort;
use App\IdentityAccess\Application\Port\EmailChangeRepositoryPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\PayloadCipherPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\IdentityAccess\Domain\User\EmailAddress;

final readonly class RequestEmailChange
{
    public function __construct(
        private EmailChangeRepositoryPort $repository,
        private ActionTokenCodecPort $tokenCodec,
        private PayloadCipherPort $payloadCipher,
        private EmailChangeOutboxPort $outbox,
        private EmailChangeRateLimitPort $limits,
        private PasswordHashingPort $hasher,
        private TransactionPort $transaction,
        private ClockPort $clock,
        private UuidPort $uuid,
    ) {
    }

    public function handle(EmailChangeRequestCommand $command): void
    {
        $newEmail = new EmailAddress($command->newEmail);
        $retryAfter = $this->limits->emailChangeRequestRetryAfter($command->userId);
        if (null !== $retryAfter) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
        }

        $this->transaction->run(function () use ($command, $newEmail): void {
            $account = $this->repository->accountForUpdate($command->userId);
            if (null === $account || !$account->isActive) {
                throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
            }
            if (!$account->verifyPassword($this->hasher, $command->currentPassword)) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidCredentials);
            }
            if ($account->email === $newEmail->value || $this->repository->emailExists($newEmail->value)) {
                throw new ApplicationFailure(ApplicationFailureCode::EmailAlreadyInUse);
            }

            $now = $this->clock->now();
            $issued = $this->tokenCodec->issue();
            $token = new UserActionToken($this->uuid->generate(), $account->id, $issued->tokenHash, UserActionTokenPurpose::ChangeEmail, $now, $now->modify('+30 minutes'));
            $this->repository->replaceToken($token, ['newEmail' => $newEmail->value], $now);
            $this->outbox->enqueueEmailChange($this->uuid->generate(), $token->id, $newEmail->value, $this->payloadCipher->encrypt(['token' => $issued->publicToken, 'locale' => $account->locale]), $now);
        });
    }
}
