<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Credentials;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ActionTokenCodecPort;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\CredentialAccount;
use App\IdentityAccess\Application\Port\CredentialRepositoryPort;
use App\IdentityAccess\Application\Port\EmailChangeRateLimitPort;
use App\IdentityAccess\Application\Port\EmailChangeRepositoryPort;
use App\IdentityAccess\Application\Port\EmailLinkOutboxPort;
use App\IdentityAccess\Application\Port\IdentityRepositoryPort;
use App\IdentityAccess\Application\Port\PasswordHashingPort;
use App\IdentityAccess\Application\Port\PayloadCipherPort;
use App\IdentityAccess\Application\Port\TelegramIdentityRepositoryPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Domain\Access\UserActionToken;
use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use App\IdentityAccess\Domain\User\EmailAddress;
use App\IdentityAccess\Domain\User\PasswordPolicy;
use App\IdentityAccess\Domain\User\PasswordPolicyViolation;
use DateTimeImmutable;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class CredentialManagement
{
    public function __construct(
        private CredentialRepositoryPort $credentials,
        private IdentityRepositoryPort $identity,
        private TelegramIdentityRepositoryPort $telegram,
        private EmailChangeRepositoryPort $actions,
        private EmailLinkOutboxPort $outbox,
        private EmailChangeRateLimitPort $emailLimits,
        private PayloadCipherPort $cipher,
        private ActionTokenCodecPort $tokens,
        private PasswordHashingPort $passwords,
        private PasswordPolicy $passwordPolicy,
        private ClockPort $clock,
        private TransactionPort $transaction,
        private UuidPort $uuid,
    ) {
    }

    /** @return array{hasEmail: bool, hasTelegram: bool, emailPending: bool, mergePending: bool, telegramDeactivationPending: bool} */
    public function settings(string $userId, string $sessionId): array
    {
        return $this->transaction->run(function () use ($userId, $sessionId): array {
            $user = $this->requireSession($userId, $sessionId, $this->clock->now());

            return [
                'hasEmail' => null !== $user->password,
                'hasTelegram' => null !== $user->telegramId,
                'emailPending' => null === $user->password && null !== $this->credentials->currentAction($userId, $sessionId, UserActionTokenPurpose::LinkEmail),
                'mergePending' => null !== $this->credentials->currentAction($userId, $sessionId, UserActionTokenPurpose::MergeAccount),
                'telegramDeactivationPending' => null === $user->password && null !== $this->credentials->currentAction($userId, $sessionId, UserActionTokenPurpose::DeactivateWithTelegram),
            ];
        });
    }

    public function authorizeTelegramChange(string $userId, string $sessionId, #[SensitiveParameter] string $password): ?int
    {
        return $this->transaction->run(function () use ($userId, $sessionId, $password): ?int {
            $user = $this->requireSession($userId, $sessionId, $this->clock->now());
            if (null !== $user->telegramId && true !== $user->password?->verifyPassword($this->passwords, $password)) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidCredentials);
            }

            return $user->telegramId;
        });
    }

    public function applyTelegram(string $userId, string $sessionId, int $telegramId, ?int $expected): string
    {
        return $this->transaction->run(function () use ($userId, $sessionId, $telegramId, $expected): string {
            $this->telegram->lockIdentity($telegramId);
            $now = $this->clock->now();
            $user = $this->requireSession($userId, $sessionId, $now);
            if ($expected !== $user->telegramId) {
                throw new ApplicationFailure(ApplicationFailureCode::CredentialConflict);
            }
            if ($telegramId === $user->telegramId) {
                return 'linked';
            }

            $other = $this->credentials->telegramOwner($telegramId);
            if (null !== $other) {
                $this->replaceMergeProof($userId, $sessionId, $other, null === $expected ? 'link_telegram' : 'replace_telegram', $now, $telegramId);

                return 'merge_required';
            }

            $this->credentials->setTelegram($userId, $telegramId);
            if (null !== $expected) {
                $this->credentials->revokeOtherSessions($userId, $sessionId, $now);
            }

            return 'linked';
        });
    }

    public function requestEmail(string $userId, string $sessionId, string $rawEmail, #[SensitiveParameter] string $password): void
    {
        try {
            $email = (new EmailAddress($rawEmail))->value;
        } catch (InvalidArgumentException) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }

        $retryAfter = $this->emailLimits->emailChangeRequestRetryAfter($userId);
        if (null !== $retryAfter) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
        }

        $this->transaction->run(function () use ($userId, $sessionId, $email, $password): void {
            $now = $this->clock->now();
            $user = $this->requireSession($userId, $sessionId, $now);
            if (null !== $user->password) {
                throw new ApplicationFailure(ApplicationFailureCode::CredentialConflict);
            }

            $other = $this->identity->loginIdentityByEmail($email);
            $hash = null;
            if (null === $other) {
                try {
                    $this->passwordPolicy->assertSatisfied($password);
                } catch (PasswordPolicyViolation) {
                    throw new ApplicationFailure(ApplicationFailureCode::PasswordPolicyViolation);
                }
                $hash = $this->passwords->hash($password);
            } elseif (!$other->verifyPassword($this->passwords, $password)) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidCredentials);
            }

            $issued = $this->tokens->issue();
            $token = new UserActionToken($this->uuid->generate(), $userId, $issued->tokenHash, UserActionTokenPurpose::LinkEmail, $now, $now->modify('+30 minutes'));
            $this->credentials->replaceAction($token, [
                'sessionId' => $sessionId,
                'email' => $email,
                'proposedPasswordHash' => $hash,
                'targetId' => $other?->id,
            ], $now);
            $this->outbox->enqueueEmailLink($this->uuid->generate(), $token->id, $email, $this->cipher->encrypt(['token' => $issued->publicToken, 'locale' => $user->locale]), $now);
        });
    }

    public function confirmEmail(string $userId, string $sessionId, #[SensitiveParameter] string $publicToken): string
    {
        $digest = $this->tokens->digest($publicToken);
        if (null === $digest) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
        }

        return $this->transaction->run(function () use ($userId, $sessionId, $digest): string {
            $now = $this->clock->now();
            $user = $this->requireSession($userId, $sessionId, $now);
            if ($userId !== $this->actions->userIdForTokenHash($digest)) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
            }

            $token = $this->actions->tokenForUpdate($digest);
            if (null === $token || $token->userId !== $userId || UserActionTokenPurpose::LinkEmail !== $token->purpose || $sessionId !== ($token->payload['sessionId'] ?? null)) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
            }
            if (null !== $token->usedAt || null !== $token->invalidatedAt || $token->expiresAt <= $now) {
                throw new ApplicationFailure(match (true) {
                    null !== $token->usedAt => ApplicationFailureCode::TokenUsed, null !== $token->invalidatedAt => ApplicationFailureCode::TokenInvalidated, default => ApplicationFailureCode::TokenExpired,
                });
            }
            $email = $token->payload['email'] ?? null;
            $hash = $token->payload['proposedPasswordHash'] ?? null;
            $targetId = $token->payload['targetId'] ?? null;
            if (null !== $user->password || !is_string($email)) {
                throw new ApplicationFailure(ApplicationFailureCode::CredentialConflict);
            }

            $owner = $this->identity->loginIdentityByEmail($email);
            if (is_string($targetId) && $targetId === $owner?->id) {
                $this->replaceMergeProof($userId, $sessionId, $targetId, 'link_email', $now);
                $this->credentials->useAction($token->id, $now);

                return 'merge_required';
            }
            if (null !== $owner || !is_string($hash)) {
                throw new ApplicationFailure(ApplicationFailureCode::CredentialConflict);
            }
            $this->credentials->setEmail($userId, $email, $hash);
            $this->credentials->useAction($token->id, $now);

            return 'linked';
        });
    }

    public function authorizeTelegramDeactivation(string $userId, string $sessionId): int
    {
        return $this->transaction->run(function () use ($userId, $sessionId): int {
            $user = $this->requireSession($userId, $sessionId, $this->clock->now());
            if (null === $user->telegramId || null !== $user->password) {
                throw new ApplicationFailure(ApplicationFailureCode::CredentialConflict);
            }

            return $user->telegramId;
        });
    }

    public function proveTelegramDeactivation(string $userId, string $sessionId, int $telegramId): void
    {
        $this->transaction->run(function () use ($userId, $sessionId, $telegramId): void {
            $now = $this->clock->now();
            $user = $this->requireSession($userId, $sessionId, $now);
            if ($user->telegramId !== $telegramId || null !== $user->password) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidCredentials);
            }
            $proof = new UserActionToken(
                $this->uuid->generate(),
                $userId,
                $this->tokens->issue()->tokenHash,
                UserActionTokenPurpose::DeactivateWithTelegram,
                $now,
                $now->modify('+10 minutes'),
            );
            $this->credentials->replaceAction($proof, ['sessionId' => $sessionId, 'telegramId' => $telegramId], $now);
        });
    }

    public function confirmTelegramDeactivation(string $userId, string $sessionId): void
    {
        $this->transaction->run(function () use ($userId, $sessionId): void {
            $now = $this->clock->now();
            $user = $this->requireSession($userId, $sessionId, $now);
            $proof = $this->credentials->currentAction($userId, $sessionId, UserActionTokenPurpose::DeactivateWithTelegram);
            if (null === $proof || null === $user->telegramId || $user->telegramId !== ($proof->payload['telegramId'] ?? null) || null !== $user->password) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidToken);
            }
            $this->credentials->useAction($proof->id, $now);
            $this->credentials->deactivate($userId);
            $this->credentials->revokeOtherSessions($userId, null, $now);
        });
    }

    public function cancel(string $userId, string $sessionId, ?UserActionTokenPurpose $purpose = null): void
    {
        $this->transaction->run(function () use ($userId, $sessionId, $purpose): void {
            $now = $this->clock->now();
            $this->requireSession($userId, $sessionId, $now);
            $this->credentials->cancelActions($userId, $sessionId, $now, $purpose);
        });
    }

    private function requireSession(string $userId, string $sessionId, DateTimeImmutable $now): CredentialAccount
    {
        $user = $this->credentials->accountForUpdate($userId);
        if (null === $user || !$user->active || !$this->credentials->activeSession($userId, $sessionId, $now)) {
            throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
        }

        return $user;
    }

    private function replaceMergeProof(string $userId, string $sessionId, string $targetId, string $mode, DateTimeImmutable $now, ?int $telegramId = null): void
    {
        $proof = new UserActionToken($this->uuid->generate(), $userId, $this->tokens->issue()->tokenHash, UserActionTokenPurpose::MergeAccount, $now, $now->modify('+10 minutes'));
        $this->credentials->replaceAction($proof, [
            'sessionId' => $sessionId,
            'targetId' => $targetId,
            'mode' => $mode,
            'telegramId' => $telegramId,
        ], $now);
    }
}
