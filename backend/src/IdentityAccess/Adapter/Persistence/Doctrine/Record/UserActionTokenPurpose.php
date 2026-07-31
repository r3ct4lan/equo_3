<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine\Record;

enum UserActionTokenPurpose: string
{
    case ActivateAccount = 'ACTIVATE_ACCOUNT';
    case ResetPassword = 'RESET_PASSWORD';
    case ChangeEmail = 'CHANGE_EMAIL';
}
