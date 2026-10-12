<?php

declare(strict_types=1);

namespace App\Debts\Domain;

enum DebtUpdateFailure
{
    case Forbidden;
    case Deleted;
    case Frozen;
    case VersionConflict;
    case InvalidParticipant;
    case InactiveParticipant;
}
