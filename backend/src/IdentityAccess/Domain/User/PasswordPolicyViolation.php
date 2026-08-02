<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\User;

use DomainException;

final class PasswordPolicyViolation extends DomainException
{
}
