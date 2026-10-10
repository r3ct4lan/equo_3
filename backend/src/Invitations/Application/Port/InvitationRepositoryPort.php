<?php

declare(strict_types=1);

namespace App\Invitations\Application\Port;

use App\Invitations\Domain\ConnectInvitation;
use DateTimeImmutable;

interface InvitationRepositoryPort
{
    public function find(string $id, bool $forUpdate = false): ?ConnectInvitation;

    public function available(string $authorId, DateTimeImmutable $now): ?ConnectInvitation;

    public function save(ConnectInvitation $invitation): void;
}
