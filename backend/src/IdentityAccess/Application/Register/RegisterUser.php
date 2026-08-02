<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Register;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Api\RegisterResult;
use App\IdentityAccess\Application\Api\UserView;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\EmailOutboxPort;
use App\IdentityAccess\Application\Port\IdempotencyPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\PayloadCipherPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\IdentityAccess\Application\Port\StoredHttpResult;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\IdentityAccess\Domain\User\EmailAddress;
use App\IdentityAccess\Domain\User\PasswordPolicy;
use App\IdentityAccess\Domain\User\PasswordPolicyViolation;
use App\IdentityAccess\Domain\User\User;
use DateTimeImmutable;
use UnexpectedValueException;

final readonly class RegisterUser
{
    private const string IDEMPOTENCY_SCOPE = 'public';
    private const string IDEMPOTENCY_OPERATION = 'register';

    public function __construct(
        private PasswordPolicy $passwordPolicy,
        private PasswordHashingPort $passwordHasher,
        private ActionTokenCodecPort $tokenCodec,
        private PayloadCipherPort $payloadCipher,
        private IdentityRepositoryPort $identityRepository,
        private IdempotencyPort $idempotency,
        private EmailOutboxPort $emailOutbox,
        private RateLimitPort $rateLimit,
        private TransactionPort $transaction,
        private ClockPort $clock,
        private UuidPort $uuid,
    ) {
    }

    public function handle(RegisterCommand $command): RegisterResult
    {
        $email = new EmailAddress($command->email);

        try {
            $this->passwordPolicy->assertSatisfied($command->password);
        } catch (PasswordPolicyViolation) {
            throw new ApplicationFailure(ApplicationFailureCode::PasswordPolicyViolation);
        }

        $canonicalRequest = json_encode([
            'email' => $email->value,
            'name' => $command->name,
            'password' => $command->password,
        ], JSON_THROW_ON_ERROR);
        $now = $this->clock->now();

        return $this->transaction->run(function () use ($command, $email, $canonicalRequest, $now): RegisterResult {
            $stored = $this->idempotency->begin(
                self::IDEMPOTENCY_SCOPE,
                self::IDEMPOTENCY_OPERATION,
                $command->idempotencyKey,
                $canonicalRequest,
                $now,
            );

            if ($stored instanceof StoredHttpResult) {
                return $this->restore($stored);
            }

            $retryAfter = $this->rateLimit->registrationRetryAfter($command->ipAddress, $email->value);

            if (null !== $retryAfter) {
                throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
            }

            if ($this->identityRepository->emailExists($email->value)) {
                throw new ApplicationFailure(ApplicationFailureCode::EmailAlreadyExists);
            }

            $user = User::register(
                $this->uuid->generate(),
                $command->name,
                $email,
                $this->passwordHasher->hash($command->password),
                $now,
            );
            $issuedToken = $this->tokenCodec->issue();
            $token = new UserActionToken(
                $this->uuid->generate(),
                $user->id,
                $issuedToken->tokenHash,
                UserActionTokenPurpose::ActivateAccount,
                $now,
                $now->modify('+24 hours'),
            );

            $this->identityRepository->addRegistration($user, $token);
            $this->emailOutbox->enqueueActivation(
                $this->uuid->generate(),
                $token->id,
                $email->value,
                $this->payloadCipher->encrypt(['token' => $issuedToken->publicToken]),
                $now,
            );

            $result = new RegisterResult(new UserView(
                $user->id,
                $user->name,
                $user->email->value,
                $user->isActive(),
                $user->createdAt,
            ));
            $this->idempotency->complete(
                self::IDEMPOTENCY_SCOPE,
                self::IDEMPOTENCY_OPERATION,
                $command->idempotencyKey,
                201,
                $result->toArray(),
                null,
            );

            return $result;
        });
    }

    private function restore(StoredHttpResult $stored): RegisterResult
    {
        $user = $stored->body['user'] ?? null;

        if (201 !== $stored->status || !is_array($user)
            || !isset($user['id'], $user['name'], $user['email'], $user['isActive'], $user['createdAt'])
            || !is_string($user['id']) || !is_string($user['name']) || !is_string($user['email'])
            || !is_bool($user['isActive']) || !is_string($user['createdAt'])) {
            throw new UnexpectedValueException('Stored registration response is invalid.');
        }

        return new RegisterResult(new UserView(
            $user['id'],
            $user['name'],
            $user['email'],
            $user['isActive'],
            new DateTimeImmutable($user['createdAt']),
        ));
    }
}
