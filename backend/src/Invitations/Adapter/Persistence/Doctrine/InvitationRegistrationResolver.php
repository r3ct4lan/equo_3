<?php

declare(strict_types=1);

namespace App\Invitations\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Api\PendingInvitationRegistration;
use App\Invitations\Application\InvitationService;
use App\Invitations\Application\Port\InvitationTokenPort;

final readonly class InvitationRegistrationResolver implements PendingInvitationRegistration
{
    public function __construct(private InvitationService $invitations, private InvitationTokenPort $tokens)
    {
    }

    public function resolve(string $token): string
    {
        $preview = $this->invitations->preview($token);
        if (!$preview['canAccept']) {
            throw new ApplicationFailure(ApplicationFailureCode::InactiveUser);
        }

        return $this->tokens->verify($token)
            ?? throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
    }
}
