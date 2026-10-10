<?php

declare(strict_types=1);

namespace App\Invitations\Adapter\Security;

use App\Invitations\Application\Port\InvitationRateLimitPort;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class InvitationRateLimit implements InvitationRateLimitPort
{
    public function __construct(private RateLimiterFactory $invitationIpLimiter, private RateLimiterFactory $invitationIdLimiter)
    {
    }

    public function retryAfter(string $ip, ?string $invitationId): ?int
    {
        $limits = [$this->invitationIpLimiter->create(hash('sha256', $ip))->consume()];
        if (null !== $invitationId) {
            $limits[] = $this->invitationIdLimiter->create($invitationId)->consume();
        }
        $retry = null;
        foreach ($limits as $limit) {
            if (!$limit->isAccepted()) {
                $retry = max($retry ?? 1, $limit->getRetryAfter()->getTimestamp() - time(), 1);
            }
        }

        return $retry;
    }
}
