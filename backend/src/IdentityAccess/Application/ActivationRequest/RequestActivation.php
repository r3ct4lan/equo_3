<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\ActivationRequest;

use App\IdentityAccess\Application\Api\ActivationRequestResult;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ActivationRequestRepositoryPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\EmailOutboxPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\PayloadCipherPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\IdentityAccess\Domain\User\EmailAddress;

final readonly class RequestActivation
{
    public function __construct(
        private ActivationRequestRepositoryPort $repository,
        private PasswordHashingPort $passwordHasher,
        private ActionTokenCodecPort $tokenCodec,
        private PayloadCipherPort $payloadCipher,
        private EmailOutboxPort $emailOutbox,
        private RateLimitPort $rateLimit,
        private TransactionPort $transaction,
        private ClockPort $clock,
        private UuidPort $uuid,
    ) {
    }

    public function handle(ActivationRequestCommand $command): ActivationRequestResult
    {
        $email = new EmailAddress($command->email);
        $retryAfter = $this->rateLimit->activationRequestRetryAfter($command->ipAddress, $email->value);

        if (null !== $retryAfter) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
        }

        $now = $this->clock->now();

        $this->transaction->run(function () use ($command, $email, $now): void {
            $account = $this->repository->accountForUpdate($email->value);

            if (null === $account
                || !$account->verifyPassword($this->passwordHasher, $command->password)
                || $account->isActive) {
                return;
            }

            $issuedToken = $this->tokenCodec->issue();
            $token = new UserActionToken(
                $this->uuid->generate(),
                $account->id,
                $issuedToken->tokenHash,
                UserActionTokenPurpose::ActivateAccount,
                $now,
                $now->modify('+24 hours'),
            );

            $this->repository->replaceActivationToken($token, $now);
            $this->emailOutbox->enqueueActivation(
                $this->uuid->generate(),
                $token->id,
                $account->email,
                $this->payloadCipher->encrypt(['token' => $issuedToken->publicToken]),
                $now,
            );
        });

        return new ActivationRequestResult();
    }
}
