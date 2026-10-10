<?php

declare(strict_types=1);

namespace App\Invitations\Application\Port;

interface InvitationTokenPort
{
    public function issue(string $id): string;

    public function reproduce(string $id, string $hash): string;

    public function verify(string $token): ?string;

    public function digest(string $token): string;
}
