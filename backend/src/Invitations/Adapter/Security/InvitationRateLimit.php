<?php

declare(strict_types=1);

namespace App\Invitations\Adapter\Security;

use App\Infrastructure\RateLimit\RequestLimiter;
use App\Invitations\Application\Port\InvitationRateLimitPort;

final readonly class InvitationRateLimit implements InvitationRateLimitPort
{
    public function __construct(private RequestLimiter $limiter)
    {
    }

    public function retryAfter(string $ip, ?string $invitationId): ?int
    {
        $limits = ['invitation:ip:'.$ip => 10];
        if (null !== $invitationId) {
            $limits['invitation:id:'.$invitationId] = 5;
        }

        return $this->limiter->consume($limits);
    }
}
