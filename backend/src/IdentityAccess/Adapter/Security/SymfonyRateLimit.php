<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\Port\RateLimitPort;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class SymfonyRateLimit implements RateLimitPort
{
    public function __construct(
        private RateLimiterFactory $registrationIpLimiter,
        private RateLimiterFactory $registrationEmailLimiter,
        private RateLimiterFactory $activationRequestIpLimiter,
        private RateLimiterFactory $activationRequestEmailLimiter,
        private RateLimiterFactory $activationIpLimiter,
        private RateLimiterFactory $activationTokenLimiter,
        private RateLimiterFactory $loginIpLimiter,
        private RateLimiterFactory $loginEmailIpLimiter,
    ) {
    }

    public function registrationRetryAfter(string $ip, string $normalizedEmail): ?int
    {
        return $this->retryAfter([
            $this->registrationIpLimiter->create($this->key('ip', $ip))->consume(),
            $this->registrationEmailLimiter->create($this->key('email', $normalizedEmail))->consume(),
        ]);
    }

    public function activationRequestRetryAfter(string $ip, string $normalizedEmail): ?int
    {
        return $this->retryAfter([
            $this->activationRequestIpLimiter->create($this->key('ip', $ip))->consume(),
            $this->activationRequestEmailLimiter->create($this->key('email', $normalizedEmail))->consume(),
        ]);
    }

    public function activationRetryAfter(string $ip, string $tokenFingerprint): ?int
    {
        return $this->retryAfter([
            $this->activationIpLimiter->create($this->key('ip', $ip))->consume(),
            $this->activationTokenLimiter->create($this->key('token', $tokenFingerprint))->consume(),
        ]);
    }

    public function loginRetryAfter(string $ip, string $normalizedEmail): ?int
    {
        return $this->retryAfter([
            $this->loginIpLimiter->create($this->key('ip', $ip))->consume(),
            $this->loginEmailIpLimiter->create($this->key('email_ip', $normalizedEmail.chr(0).$ip))->consume(),
        ]);
    }

    /** @param list<RateLimit> $limits */
    private function retryAfter(array $limits): ?int
    {
        $retryAfter = null;
        $now = microtime(true);

        foreach ($limits as $limit) {
            if ($limit->isAccepted()) {
                continue;
            }

            $seconds = max(1, (int) ceil((float) $limit->getRetryAfter()->format('U.u') - $now));
            $retryAfter = null === $retryAfter ? $seconds : max($retryAfter, $seconds);
        }

        return $retryAfter;
    }

    private function key(string $dimension, string $value): string
    {
        return $dimension.':'.hash('sha256', $value);
    }
}
