<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\Access;

enum UserActionTokenPurpose: string
{
    case ActivateAccount = 'ACTIVATE_ACCOUNT';
    case ResetPassword = 'RESET_PASSWORD';
    case ChangeEmail = 'CHANGE_EMAIL';
}
