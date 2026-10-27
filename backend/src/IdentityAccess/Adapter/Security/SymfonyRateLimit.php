<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\EmailChangeRateLimitPort;
use App\IdentityAccess\Application\Port\PasswordResetRateLimitPort;
use App\IdentityAccess\Application\Port\RateLimitPort;
use App\Infrastructure\RateLimit\RequestLimiter;

final readonly class SymfonyRateLimit implements RateLimitPort, PasswordResetRateLimitPort, EmailChangeRateLimitPort
{
    public function __construct(
        private RequestLimiter $limiter,
        private int $registrationIpLimit,
        private int $registrationEmailLimit,
        private int $activationRequestIpLimit,
        private int $activationRequestEmailLimit,
        private int $activationIpLimit,
        private int $activationTokenLimit,
    ) {
    }

    public function registrationRetryAfter(string $ip, string $normalizedEmail): ?int
    {
        return $this->limiter->consume([
            'registration:ip:'.$ip => $this->registrationIpLimit,
            'registration:email:'.$normalizedEmail => $this->registrationEmailLimit,
        ]);
    }

    public function activationRequestRetryAfter(string $ip, string $normalizedEmail): ?int
    {
        return $this->limiter->consume([
            'activation-request:ip:'.$ip => $this->activationRequestIpLimit,
            'activation-request:email:'.$normalizedEmail => $this->activationRequestEmailLimit,
        ]);
    }

    public function activationRetryAfter(string $ip, string $tokenFingerprint): ?int
    {
        return $this->limiter->consume([
            'action:ip:'.$ip => $this->activationIpLimit,
            'action:token:'.$tokenFingerprint => $this->activationTokenLimit,
        ]);
    }

    public function passwordResetRequestRetryAfter(string $ip, string $normalizedEmail): ?int
    {
        return $this->limiter->consume([
            'password-reset-request:ip:'.$ip => 20,
            'password-reset-request:email:'.$normalizedEmail => 3,
        ]);
    }

    public function passwordResetRetryAfter(string $ip, string $tokenFingerprint): ?int
    {
        return $this->activationRetryAfter($ip, $tokenFingerprint);
    }

    public function emailChangeRequestRetryAfter(string $userId): ?int
    {
        return $this->limiter->consume(['email-change:user:'.$userId => 3]);
    }

    public function emailChangeConfirmationRetryAfter(string $ip, string $tokenFingerprint): ?int
    {
        return $this->activationRetryAfter($ip, $tokenFingerprint);
    }
}
