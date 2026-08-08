<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Port;

interface AccessTokenVerifierPort
{
    public function verify(string $accessToken): ?VerifiedAccessToken;
}
