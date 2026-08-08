<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface AccessTokenIssuerPort
{
    public function issue(string $userId): IssuedAccessToken;
}
