<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Authorization;

enum ActivationAccessDenial
{
    case InvalidToken;
    case ExpiredToken;
    case UsedToken;
    case InvalidatedToken;
}
