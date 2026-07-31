<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Authorization;

use App\IdentityAccess\Domain\Access\UserActionTokenPurpose;
use DateTimeImmutable;

final class ActivationAccessPolicy
{
    public function decide(?ActivationTokenAccess $token, DateTimeImmutable $now): ActivationAccessDecision
    {
        if (null === $token || UserActionTokenPurpose::ActivateAccount !== $token->purpose) {
            return ActivationAccessDecision::deny(ActivationAccessDenial::InvalidToken);
        }

        if (null !== $token->invalidatedAt) {
            return ActivationAccessDecision::deny(ActivationAccessDenial::InvalidatedToken);
        }

        if (null !== $token->usedAt) {
            return ActivationAccessDecision::deny(ActivationAccessDenial::UsedToken);
        }

        if ($token->expiresAt <= $now) {
            return ActivationAccessDecision::deny(ActivationAccessDenial::ExpiredToken);
        }

        return ActivationAccessDecision::allow($token->userId);
    }
}
