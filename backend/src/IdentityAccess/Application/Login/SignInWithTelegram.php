<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Login;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\ClockPort;
use App\IdentityAccess\Application\Port\TelegramIdentityRepositoryPort;
use App\IdentityAccess\Application\Port\TransactionPort;
use App\IdentityAccess\Application\Port\UuidPort;
use App\IdentityAccess\Domain\User\User;
use App\IdentityAccess\Domain\User\VerifiedTelegramIdentity;

final readonly class SignInWithTelegram
{
    public function __construct(
        private TransactionPort $transaction,
        private TelegramIdentityRepositoryPort $accounts,
        private IssueUserSession $sessions,
        private ClockPort $clock,
        private UuidPort $uuid,
    ) {
    }

    public function handle(VerifiedTelegramIdentity $identity): LoginResult
    {
        return $this->transaction->run(function () use ($identity): LoginResult {
            $this->accounts->lockIdentity($identity->id);
            $account = $this->accounts->accountForUpdate($identity->id);
            $now = $this->clock->now();
            if (null === $account) {
                $created = User::registerWithTelegram($this->uuid->generate(), $identity->name, $identity->id, $now, $identity->locale);
                $this->accounts->add($created);
                $account = new LoginUserView($created->id, $created->name, null, true, $created->locale);
            }

            if (!$account->isActive) {
                throw new ApplicationFailure(ApplicationFailureCode::AccountInactive);
            }

            return $this->sessions->issue($account, $now);
        });
    }
}
