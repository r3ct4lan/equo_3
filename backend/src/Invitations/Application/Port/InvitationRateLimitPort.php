<?php

declare(strict_types=1);

namespace App\Invitations\Application\Port;

interface InvitationRateLimitPort
{
    public function retryAfter(string $ip, ?string $invitationId): ?int;
}
