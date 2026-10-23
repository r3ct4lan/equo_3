<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Login;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\LoginThrottlePort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Domain\User\EmailAddress;

final readonly class LoginUser
{
    public function __construct(
        private IdentityRepositoryPort $identityRepository,
        private PasswordHashingPort $passwordHasher,
        private IssueUserSession $issueUserSession,
        private LoginThrottlePort $loginThrottle,
        private TransactionPort $transaction,
        private ClockPort $clock,
    ) {
    }

    public function handle(LoginCommand $command): LoginResult
    {
        $email = new EmailAddress($command->email);
        $retryAfter = $this->loginThrottle->retryAfter($command->ipAddress);

        if (null !== $retryAfter) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
        }

        $now = $this->clock->now();

        try {
            $result = $this->transaction->run(function () use ($command, $email, $now): LoginResult {
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

                $currentUser = $this->identityRepository->currentUserForUpdate($identity->id);
                if (null === $currentUser || !$currentUser->isActive) {
                    throw new ApplicationFailure(ApplicationFailureCode::AccountInactive);
                }

                // A password reset may have committed while this login waited for the owner lock.
                $freshIdentity = $this->identityRepository->loginIdentityByEmail($email->value);
                if (null === $freshIdentity || $freshIdentity->id !== $identity->id
                    || (!$identity->hasSameCredentialAs($freshIdentity) && !$freshIdentity->verifyPassword($this->passwordHasher, $command->password))) {
                    throw new ApplicationFailure(ApplicationFailureCode::InvalidCredentials);
                }
                $identity = $freshIdentity;

                return $this->issueUserSession->issue(
                    new LoginUserView($identity->id, $identity->name, $identity->email, $identity->isActive),
                    $now,
                );
            });
        } catch (ApplicationFailure $failure) {
            if (ApplicationFailureCode::InvalidCredentials !== $failure->failureCode) {
                throw $failure;
            }

            $retryAfter = $this->loginThrottle->recordInvalidCredentials($command->ipAddress);
            if (null !== $retryAfter) {
                throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
            }

            throw $failure;
        }

        $this->loginThrottle->reset($command->ipAddress);

        return $result;
    }
}
