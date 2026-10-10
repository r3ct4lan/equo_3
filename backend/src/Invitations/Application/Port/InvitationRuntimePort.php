<?php

declare(strict_types=1);

namespace App\Invitations\Application\Port;

use DateTimeImmutable;

interface InvitationRuntimePort
{
    public function now(): DateTimeImmutable;

    public function newId(): string;

    public function run(callable $operation): mixed;
}
