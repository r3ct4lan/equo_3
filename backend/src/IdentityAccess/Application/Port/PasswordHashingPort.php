<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface PasswordHashingPort
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, ?string $passwordHash): bool;
}
