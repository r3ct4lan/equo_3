<?php

declare(strict_types=1);

namespace App\Invitations\Application;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Api\PendingInvitationAccess;
use App\Invitations\Application\Port\InvitationRepositoryPort;
use App\Invitations\Application\Port\InvitationTokenPort;

final readonly class PendingInvitationService
{
    public function __construct(
        private PendingInvitationAccess $pending,
        private InvitationRepositoryPort $invitations,
        private InvitationTokenPort $tokens,
        private InvitationService $service,
    ) {
    }

    /** @return array{view: array<string, mixed>, created: bool}|null */
    public function accept(string $userId, string $ip): ?array
    {
        $id = $this->pending->forUser($userId);
        if (null === $id) {
            return null;
        }

        $invitation = $this->invitations->find($id);
        if (null === $invitation) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }

        $token = $this->tokens->reproduce($id, $invitation->tokenHash);
        $result = $this->service->accept($token, $userId, $ip);
        $this->pending->clear($userId);

        return $result;
    }
}
